<?php

namespace App\Jobs;

use App\Models\Reservation;
use App\Services\Pdf\FreeReservationPdfGenerator;
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
 * Besplatna rezervacija: potvrda na email sa PDF prilogom.
 * PDF iz baze (renderBinary); na grešku: email_sent → NOT_SENT, job fail (queue retry); bez fallback regeneracije.
 * Idempotentno: invoice_sent_at + lock na EMAIL_SENDING (isto kao plaćeni job).
 */
class SendFreeReservationConfirmationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const LOG_EVENT = 'free_reservation_email';

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
        public int $reservationId
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
            'message' => $e?->getMessage(),
            'exception' => $e !== null ? $e::class : null,
        ]);
    }

    public function handle(
        FreeReservationPdfGenerator $pdfGenerator,
        ReservationEmailSendClaimService $claimService,
    ): void {
        ['reservation' => $reservation, 'claimed' => $claimed] = $claimService->claim(
            $this->reservationId,
            extraGuard: fn (Reservation $r): bool => $r->status === 'free',
        );

        if ($reservation === null || ! $claimed) {
            return;
        }

        $email = $reservation->email;
        if ($email === '' || $email === null) {
            $reservation->update(['email_sent' => Reservation::EMAIL_NOT_SENT]);

            return;
        }

        $attachmentFilename = $reservation->freeConfirmationPdfFilename();
        ReservationDocumentEmailLogger::started(self::LOG_EVENT, $reservation, $attachmentFilename);

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

        $subject = $this->resolveFreeReservationEmailSubject($emailLocale);

        $body = $this->buildConfirmationText($reservation, $emailLocale);

        $tmpPath = null;
        try {
            $pdfBinary = $pdfGenerator->renderBinary($reservation);
            if ($pdfBinary === '') {
                throw new RuntimeException('Free reservation PDF empty after renderBinary.');
            }

            $tmpPath = tempnam(sys_get_temp_dir(), 'bus_free_');
            if ($tmpPath === false) {
                throw new RuntimeException('tempnam failed for free reservation PDF attachment.');
            }

            file_put_contents($tmpPath, $pdfBinary);
            Mail::raw($body, function ($message) use ($email, $fromAddress, $fromName, $subject, $attachmentFilename, $tmpPath): void {
                $message->to($email)
                    ->from($fromAddress, $fromName)
                    ->subject($subject);
                $message->attach($tmpPath, [
                    'as' => $attachmentFilename,
                    'mime' => 'application/pdf',
                ]);
            });

            $reservation->markConfirmationEmailSent();
            ReservationDocumentEmailLogger::sent(self::LOG_EVENT, $reservation, $attachmentFilename);
        } catch (Throwable $e) {
            ReservationDocumentEmailLogger::failed(self::LOG_EVENT, $reservation, $attachmentFilename, $e);
            Log::channel('single')->error('SendFreeReservationConfirmationJob failed', [
                'reservation_id' => $reservation->id,
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

        $bodyTemplate = $this->resolveFreeReservationEmailBodyTemplate($emailLocale);

        return ReservationEmailReferenceLine::appendBeforeClosing(
            str_replace('%1$s', $name, $bodyTemplate),
            ReservationEmailReferenceLine::forReservation($reservation, $emailLocale),
        );
    }

    private function resolveFreeReservationEmailSubject(string $emailLocale): string
    {
        $fallback = $emailLocale === 'cg'
            ? 'Potvrda besplatne rezervacije'
            : 'Free reservation confirmation';

        $subject = UiText::t('emails', 'free_reservation_subject', $fallback, $emailLocale);

        if (str_contains($subject, '%')) {
            return $fallback;
        }

        return $subject;
    }

    private function resolveFreeReservationEmailBodyTemplate(string $emailLocale): string
    {
        $fallback = $this->defaultFreeReservationEmailBodyTemplate($emailLocale);

        $bodyTemplate = UiText::t('emails', 'free_reservation_body', $fallback, $emailLocale);

        if (str_contains($bodyTemplate, '%1$d') || str_contains($bodyTemplate, '%2$s')) {
            return $fallback;
        }

        return $bodyTemplate;
    }

    private function defaultFreeReservationEmailBodyTemplate(string $emailLocale): string
    {
        return $emailLocale === 'cg'
            ? "Poštovani %1\$s,\n\nVaša besplatna rezervacija parkinga je uspješno kreirana.\n\nUz ovu poruku u prilogu se nalazi potvrda besplatne rezervacije parkinga.\n\nMolimo Vas da je sačuvate radi evidencije.\n\nS poštovanjem,\nOpština Kotor"
            : "Dear %1\$s,\n\nYour free parking reservation has been successfully created.\n\nAttached to this email you will find the free parking reservation confirmation.\n\nPlease keep this for your records.\n\nBest regards,\nMunicipality of Kotor";
    }
}
