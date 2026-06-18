<?php

namespace App\Jobs;

use App\Models\Reservation;
use App\Services\Pdf\FreeReservationPdfGenerator;
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
 * After admin edits reservation operational fields: regenerate PDF and email customer.
 * Payment/fiscal snapshot fields on the reservation row are unchanged.
 */
class SendAdminUpdatedReservationDocumentJob implements ShouldQueue
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

    /**
     * @param  list<string>  $changedFields
     */
    public function __construct(
        public int $reservationId,
        public ?int $adminId = null,
        public array $changedFields = [],
    ) {}

    public function failed(?Throwable $e): void
    {
        Reservation::query()->whereKey($this->reservationId)->update([
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);

        Log::channel('payments')->error('admin_panel_reservation_update_email_exhausted', [
            'reservation_id' => $this->reservationId,
            'admin_id' => $this->adminId,
            'changed_fields' => $this->changedFields,
            'message' => $e?->getMessage(),
            'exception' => $e !== null ? $e::class : null,
        ]);
    }

    public function handle(
        PaidInvoicePdfGenerator $paidPdfGenerator,
        FreeReservationPdfGenerator $freePdfGenerator,
    ): void {
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

            if (! in_array($reservation->status, ['paid', 'free'], true)) {
                return;
            }

            if ((int) $reservation->email_sent === Reservation::EMAIL_SENDING) {
                return;
            }

            $reservation->update(['email_sent' => Reservation::EMAIL_SENDING]);
            $claimed = true;
        });

        if (! $reservation || ! $claimed) {
            return;
        }

        $email = $reservation->email;
        if ($email === '' || $email === null) {
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

        $subject = $this->buildSubject($reservation, $emailLocale);
        $body = $this->buildBody($reservation, $emailLocale);

        $tmpPath = null;
        try {
            if ($reservation->status === 'free') {
                $pdfBinary = $freePdfGenerator->renderBinary($reservation);
                $attachmentName = $reservation->freeConfirmationPdfFilename();
            } else {
                $isFiscal = $reservation->fiscal_jir !== null;
                $pdfBinary = $paidPdfGenerator->renderBinary($reservation, $isFiscal);
                $attachmentName = $reservation->invoicePdfFilename();
            }

            if ($pdfBinary === '') {
                throw new RuntimeException('Updated reservation PDF empty after renderBinary.');
            }

            $tmpPath = tempnam(sys_get_temp_dir(), 'bus_admin_upd_');
            if ($tmpPath === false) {
                throw new RuntimeException('tempnam failed for updated reservation PDF attachment.');
            }

            file_put_contents($tmpPath, $pdfBinary);

            Mail::raw($body, function ($message) use ($email, $fromAddress, $fromName, $subject, $tmpPath, $attachmentName): void {
                $message->to($email)
                    ->from($fromAddress, $fromName)
                    ->subject($subject);
                $message->attach($tmpPath, [
                    'as' => $attachmentName,
                    'mime' => 'application/pdf',
                ]);
            });

            $reservation->markConfirmationEmailSent();

            Log::channel('payments')->info('admin_panel_reservation_update_email_sent', [
                'reservation_id' => $reservation->id,
                'reservation_kind' => $reservation->reservation_kind,
                'merchant_transaction_id' => $reservation->merchant_transaction_id,
                'admin_id' => $this->adminId,
                'email' => $email,
                'changed_fields' => $this->changedFields,
                'status' => $reservation->status,
            ]);
        } catch (Throwable $e) {
            Log::channel('payments')->warning('admin_panel_reservation_update_email_failed', [
                'reservation_id' => $reservation->id,
                'admin_id' => $this->adminId,
                'changed_fields' => $this->changedFields,
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

    private function buildSubject(Reservation $reservation, string $emailLocale): string
    {
        if ($reservation->status === 'free') {
            $template = UiText::t(
                'emails',
                'free_reservation_updated_email_subject',
                'Updated free reservation confirmation #%1$d',
                $emailLocale
            );

            return sprintf($template, $reservation->id);
        }

        $template = UiText::t(
            'emails',
            'paid_invoice_updated_email_subject',
            'Updated reservation confirmation #%1$d',
            $emailLocale
        );

        return sprintf($template, $reservation->id);
    }

    private function buildBody(Reservation $reservation, string $emailLocale): string
    {
        if ($reservation->status === 'free') {
            return UiText::t(
                'emails',
                'free_reservation_updated_email_body',
                "Hello,\n\nYour free reservation details have been updated. The updated confirmation PDF is attached.\n\nThank you.",
                $emailLocale
            );
        }

        $jirSuffix = '';
        if ($reservation->fiscal_jir) {
            $jirSuffix = "\n\n".sprintf(
                UiText::t('emails', 'paid_invoice_email_jir_line', 'JIR: %1$s', $emailLocale),
                $reservation->fiscal_jir
            );
        }

        $bodyKey = $reservation->isDailyTicket()
            ? 'paid_invoice_updated_email_body_daily_ticket'
            : 'paid_invoice_updated_email_body';

        $fallback = $reservation->isDailyTicket()
            ? "Your reservation details have been updated. The updated reservation/invoice PDF is attached. Payment and fiscalization data have not been changed.%1\$s"
            : "Your reservation details have been updated. The updated reservation/invoice PDF is attached. Payment and fiscalization data have not been changed.%1\$s";

        $template = UiText::t('emails', $bodyKey, $fallback, $emailLocale);

        return sprintf($template, $jirSuffix);
    }
}
