<?php

namespace App\Jobs;

use App\Models\Reservation;
use App\Services\Pdf\FreeReservationPdfGenerator;
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
 * After admin edits reservation operational fields: regenerate PDF and email customer.
 * Payment/fiscal snapshot fields on the reservation row are unchanged.
 */
class SendAdminUpdatedReservationDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const LOG_EVENT = 'admin_panel_reservation_update_email';

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

        Log::channel('payments')->error(self::LOG_EVENT.'_exhausted', [
            'event' => self::LOG_EVENT.'_exhausted',
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
        ReservationEmailSendClaimService $claimService,
    ): void {
        ['reservation' => $reservation, 'claimed' => $claimed] = $claimService->claim(
            $this->reservationId,
            skipIfInvoiceAlreadySent: false,
            extraGuard: fn (Reservation $r): bool => in_array($r->status, ['paid', 'free'], true),
        );

        if ($reservation === null || ! $claimed) {
            return;
        }

        $email = $reservation->email;
        if ($email === '' || $email === null) {
            $reservation->update(['email_sent' => Reservation::EMAIL_NOT_SENT]);

            return;
        }

        if ($reservation->status === 'free') {
            $attachmentFilename = $reservation->freeConfirmationPdfFilename();
        } else {
            $attachmentFilename = $reservation->invoicePdfFilename();
        }

        ReservationDocumentEmailLogger::started(self::LOG_EVENT, $reservation, $attachmentFilename, [
            'admin_id' => $this->adminId,
            'changed_fields' => $this->changedFields,
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

        $subject = $this->buildSubject($reservation, $emailLocale);
        $body = $this->buildBody($reservation, $emailLocale);

        $tmpPath = null;
        try {
            if ($reservation->status === 'free') {
                $pdfBinary = $freePdfGenerator->renderBinary($reservation);
            } else {
                $isFiscal = $reservation->fiscal_jir !== null;
                $pdfBinary = $paidPdfGenerator->renderBinary($reservation, $isFiscal);
            }

            if ($pdfBinary === '') {
                throw new RuntimeException('Updated reservation PDF empty after renderBinary.');
            }

            $tmpPath = tempnam(sys_get_temp_dir(), 'bus_admin_upd_');
            if ($tmpPath === false) {
                throw new RuntimeException('tempnam failed for updated reservation PDF attachment.');
            }

            file_put_contents($tmpPath, $pdfBinary);

            Mail::raw($body, function ($message) use ($email, $fromAddress, $fromName, $subject, $tmpPath, $attachmentFilename): void {
                $message->to($email)
                    ->from($fromAddress, $fromName)
                    ->subject($subject);
                $message->attach($tmpPath, [
                    'as' => $attachmentFilename,
                    'mime' => 'application/pdf',
                ]);
            });

            $reservation->markConfirmationEmailSent();

            ReservationDocumentEmailLogger::sent(self::LOG_EVENT, $reservation, $attachmentFilename, [
                'admin_id' => $this->adminId,
                'changed_fields' => $this->changedFields,
            ]);
        } catch (Throwable $e) {
            ReservationDocumentEmailLogger::failed(self::LOG_EVENT, $reservation, $attachmentFilename, $e, [
                'admin_id' => $this->adminId,
                'changed_fields' => $this->changedFields,
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
            $body = UiText::t(
                'emails',
                'free_reservation_updated_email_body',
                "Hello,\n\nYour free reservation details have been updated. The updated confirmation PDF is attached.\n\nThank you.",
                $emailLocale
            );

            return ReservationEmailReferenceLine::appendBeforeClosing(
                $body,
                ReservationEmailReferenceLine::forReservation($reservation, $emailLocale),
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

        return ReservationEmailReferenceLine::appendBeforeClosing(
            sprintf($template, $jirSuffix),
            ReservationEmailReferenceLine::forReservation($reservation, $emailLocale),
        );
    }
}
