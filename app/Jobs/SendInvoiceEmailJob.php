<?php

namespace App\Jobs;

use App\Models\Reservation;
use App\Services\Pdf\PaidInvoicePdfGenerator;
use App\Support\UiText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Sends invoice/confirmation email to customer. Fiscal or non-fiscal PDF attached.
 * PDF se generiše iz baze (renderBinary) u memoriji / privremenom fajlu; ne čuva se u storage.
 * Idempotent: ako je mail već poslat (invoice_sent_at set) – ne šalji ponovo.
 * Na grešku PDF-a ili slanja: email_sent → NOT_SENT, job baca izuzetak (Laravel retry); nema „regeneriši u istom prolazu“.
 */
class SendInvoiceEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 45;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 180, 600];
    }

    public function __construct(
        public int $reservationId,
        public bool $isFiscal = true
    ) {}

    public function failed(?Throwable $e): void
    {
        Reservation::query()->whereKey($this->reservationId)->update([
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
        $mtid = Reservation::query()->whereKey($this->reservationId)->value('merchant_transaction_id');
        Log::channel('payments')->error('invoice_email_job_exhausted', [
            'reservation_id' => $this->reservationId,
            'merchant_transaction_id' => $mtid,
            'is_fiscal' => $this->isFiscal,
            'message' => $e?->getMessage(),
            'exception' => $e !== null ? $e::class : null,
        ]);
    }

    public function handle(PaidInvoicePdfGenerator $pdfGenerator): void
    {
        /** @var Reservation|null $reservation */
        $reservation = null;
        $claimed = false;

        DB::transaction(function () use (&$reservation, &$claimed): void {
            $reservation = Reservation::query()
                ->whereKey($this->reservationId)
                ->lockForUpdate()
                ->first();

            if (! $reservation) {
                return;
            }

            if ($reservation->invoice_sent_at !== null) {
                return;
            }

            if ((int) $reservation->email_sent === Reservation::EMAIL_SENDING) {
                return;
            }

            $reservation->update(['email_sent' => Reservation::EMAIL_SENDING]);
            $claimed = true;
        });

        if (! $reservation) {
            Log::channel('payments')->warning('invoice_email_reservation_missing', [
                'reservation_id' => $this->reservationId,
                'is_fiscal' => $this->isFiscal,
                'job' => static::class,
            ]);

            return;
        }

        if (! $claimed) {
            return;
        }

        // Primalac mora biti snapshot na rezervaciji (isti kao u PDF-u). user.email se menja u profilu;
        // admin izmena rezervacije menja samo reservations.email — ne slati na stari nalog.
        $email = $reservation->email;
        if (empty($email)) {
            $reservation->update(['email_sent' => Reservation::EMAIL_NOT_SENT]);

            return;
        }

        $emailLocale = $reservation->user_id
            ? ($reservation->user?->lang ?? 'en')
            : ($reservation->preferred_locale ?? 'en');
        if (! in_array($emailLocale, ['en', 'cg'], true)) {
            $emailLocale = 'en';
        }
        $previousLocale = app()->getLocale();
        app()->setLocale($emailLocale);

        $fromAddress = config('mail.from.address');
        $fromName = config('mail.from.name');

        $subjectTemplate = UiText::t(
            'emails',
            'paid_invoice_email_subject',
            'Reservation confirmation #%1$d',
            $emailLocale
        );
        $subject = sprintf($subjectTemplate, $reservation->id);

        $body = $this->buildConfirmationText($reservation, $emailLocale);

        $tmpPath = null;
        try {
            $pdfBinary = $pdfGenerator->renderBinary($reservation, $this->isFiscal);
            if ($pdfBinary === '') {
                throw new RuntimeException('Paid invoice PDF empty after renderBinary.');
            }

            $tmpPath = tempnam(sys_get_temp_dir(), 'bus_inv_');
            if ($tmpPath === false) {
                throw new RuntimeException('tempnam failed for invoice PDF attachment.');
            }

            file_put_contents($tmpPath, $pdfBinary);
            Mail::raw(
                $body,
                function ($message) use ($reservation, $email, $fromAddress, $fromName, $tmpPath, $subject): void {
                    $message->to($email)
                        ->from($fromAddress, $fromName)
                        ->subject($subject);
                    $message->attach($tmpPath, [
                        'as' => 'invoice-'.$reservation->id.'.pdf',
                        'mime' => 'application/pdf',
                    ]);
                }
            );

            $reservation->markConfirmationEmailSent();
            Log::channel('payments')->info('invoice_email_sent', [
                'reservation_id' => $reservation->id,
                'merchant_transaction_id' => $reservation->merchant_transaction_id,
                'user_id' => $reservation->user_id,
                'is_fiscal_pdf' => $this->isFiscal,
            ]);
        } catch (Throwable $e) {
            Log::channel('payments')->warning('invoice_email_send_failed', [
                'reservation_id' => $reservation->id,
                'merchant_transaction_id' => $reservation->merchant_transaction_id,
                'user_id' => $reservation->user_id,
                'is_fiscal' => $this->isFiscal,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            Log::channel('single')->error('SendInvoiceEmailJob failed', [
                'reservation_id' => $reservation->id,
                'is_fiscal' => $this->isFiscal,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            $reservation->update(['email_sent' => Reservation::EMAIL_NOT_SENT]);
            throw $e;
        } finally {
            if (is_string($tmpPath)) {
                @unlink($tmpPath);
            }
            app()->setLocale($previousLocale);
        }
    }

    private function buildConfirmationText(Reservation $reservation, string $emailLocale): string
    {
        $jirSuffix = '';
        if ($reservation->fiscal_jir) {
            $jirSuffix = "\n\n".sprintf(
                UiText::t('emails', 'paid_invoice_email_jir_line', 'JIR: %1$s', $emailLocale),
                $reservation->fiscal_jir
            );
        }

        $bodyKey = $reservation->isDailyTicket()
            ? 'paid_invoice_email_body_daily_ticket'
            : 'paid_invoice_email_body';

        $bodyTemplate = UiText::t(
            'emails',
            $bodyKey,
            $reservation->isDailyTicket()
                ? "Hello,\n\nYour daily ticket reservation #%1\$d is confirmed. Valid on: %2\$s.%3\$s\n\nA PDF copy of your invoice or confirmation is attached.\n\nThank you."
                : "Hello,\n\nYour paid parking reservation #%1\$d is confirmed for date %2\$s.%3\$s\n\nA PDF copy of your invoice or confirmation is attached.\n\nThank you.",
            $emailLocale
        );

        return sprintf(
            $bodyTemplate,
            $reservation->id,
            $reservation->reservation_date->format('Y-m-d'),
            $jirSuffix
        );
    }
}
