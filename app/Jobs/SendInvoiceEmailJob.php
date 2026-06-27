<?php

namespace App\Jobs;

use App\Models\Reservation;
use App\Services\Pdf\PaidInvoicePdfGenerator;
use App\Services\Reservation\ReservationEmailSendClaimService;
use App\Support\ReservationDocumentEmailLogger;
use App\Support\ReservationEmailReferenceLine;
use App\Support\UiText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

    private const LOG_EVENT = 'paid_invoice_email';

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
        Log::channel('payments')->error(self::LOG_EVENT.'_job_exhausted', [
            'event' => self::LOG_EVENT.'_job_exhausted',
            'reservation_id' => $this->reservationId,
            'merchant_transaction_id' => $mtid,
            'is_fiscal' => $this->isFiscal,
            'message' => $e?->getMessage(),
            'exception' => $e !== null ? $e::class : null,
        ]);
    }

    public function handle(
        PaidInvoicePdfGenerator $pdfGenerator,
        ReservationEmailSendClaimService $claimService,
    ): void {
        ['reservation' => $reservation, 'claimed' => $claimed] = $claimService->claim($this->reservationId);

        if ($reservation === null) {
            Log::channel('payments')->warning(self::LOG_EVENT.'_reservation_missing', [
                'event' => self::LOG_EVENT.'_reservation_missing',
                'reservation_id' => $this->reservationId,
                'is_fiscal' => $this->isFiscal,
                'job' => static::class,
            ]);

            return;
        }

        if (! $claimed) {
            return;
        }

        $email = $reservation->email;
        if (empty($email)) {
            $reservation->update(['email_sent' => Reservation::EMAIL_NOT_SENT]);

            return;
        }

        $attachmentFilename = $reservation->invoicePdfFilename();
        ReservationDocumentEmailLogger::started(self::LOG_EVENT, $reservation, $attachmentFilename, [
            'is_fiscal_pdf' => $this->isFiscal,
        ]);

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

        $subject = UiText::t(
            'emails',
            'paid_invoice_email_subject',
            'Confirmation of reservation payment - Municipality of Kotor',
            $emailLocale
        );

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
                function ($message) use ($reservation, $email, $fromAddress, $fromName, $tmpPath, $subject, $attachmentFilename): void {
                    $message->to($email)
                        ->from($fromAddress, $fromName)
                        ->subject($subject);
                    $message->attach($tmpPath, [
                        'as' => $attachmentFilename,
                        'mime' => 'application/pdf',
                    ]);
                }
            );

            $reservation->markConfirmationEmailSent();
            ReservationDocumentEmailLogger::sent(self::LOG_EVENT, $reservation, $attachmentFilename, [
                'is_fiscal_pdf' => $this->isFiscal,
            ]);
        } catch (Throwable $e) {
            ReservationDocumentEmailLogger::failed(self::LOG_EVENT, $reservation, $attachmentFilename, $e, [
                'is_fiscal' => $this->isFiscal,
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
        $name = trim((string) ($reservation->user_name ?? ''));
        if ($name === '') {
            $name = $emailLocale === 'cg' ? 'korisniče' : 'customer';
        }

        $generatedAt = now('Europe/Podgorica')->format('d.m.Y H:i');

        $bodyTemplate = $this->resolvePaidInvoiceEmailBodyTemplate($emailLocale);

        return ReservationEmailReferenceLine::appendBeforeClosing(
            str_replace(
                ['%1$s', '%2$s'],
                [$name, $generatedAt],
                $bodyTemplate,
            ),
            ReservationEmailReferenceLine::forReservation($reservation, $emailLocale),
        );
    }

    private function resolvePaidInvoiceEmailBodyTemplate(string $emailLocale): string
    {
        $fallback = $this->defaultPaidInvoiceEmailBodyTemplate($emailLocale);

        $bodyTemplate = UiText::t(
            'emails',
            'paid_invoice_email_body',
            $fallback,
            $emailLocale
        );

        if (str_contains($bodyTemplate, '%1$d') || str_contains($bodyTemplate, '%3$s')) {
            return $fallback;
        }

        return $bodyTemplate;
    }

    private function defaultPaidInvoiceEmailBodyTemplate(string $emailLocale): string
    {
        return $emailLocale === 'cg'
            ? "Poštovani, %1\$s\n\nVaša rezervacija je uspješno potvrđena!\n\nUz ovu poruku u prilogu se nalazi Vaš račun za plaćanje.\n\nMolimo Vas da ga sačuvate radi evidencije.\n\nS poštovanjem,\nOpština Kotor\nOva poruka je automatski generisana %2\$s"
            : "Dear, %1\$s\n\nYour reservation has been successfully confirmed!\n\nAttached to this email you will find your Invoice for the payment.\n\nPlease keep it for your records.\n\nBest regards,\nMunicipality of Kotor\nThis message was generated automatically %2\$s";
    }
}
