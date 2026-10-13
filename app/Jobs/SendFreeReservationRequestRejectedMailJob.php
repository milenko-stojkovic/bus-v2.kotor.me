<?php

namespace App\Jobs;

use App\Mail\FreeReservationRequestRejectedMail;
use App\Models\FreeReservationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Obavještenje agenciji da je zahtjev za besplatnu rezervaciju odbijen.
 * Šalje se poslije commit-a odluke. Pad slanja ne mijenja status zahtjeva.
 * Ponovni ulazak ne šalje drugi mejl ako je rejection_notification_sent_at već upisan.
 */
class SendFreeReservationRequestRejectedMailJob implements ShouldBeUnique, ShouldQueue
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
        public int $freeReservationRequestId
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->freeReservationRequestId;
    }

    public function failed(?Throwable $e): void
    {
        Log::channel('payments')->error('free_reservation_request_rejected_email_exhausted', [
            'event' => 'free_reservation_request_rejected_email_exhausted',
            'free_reservation_request_id' => $this->freeReservationRequestId,
            'message' => $e?->getMessage(),
            'exception' => $e !== null ? $e::class : null,
        ]);
    }

    public function handle(): void
    {
        $payload = DB::transaction(function (): ?array {
            $request = FreeReservationRequest::query()
                ->whereKey($this->freeReservationRequestId)
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                return null;
            }

            if ($request->status !== FreeReservationRequest::STATUS_REJECTED) {
                return null;
            }

            if ($request->rejection_notification_sent_at !== null) {
                return null;
            }

            $email = trim((string) $request->institution_email);
            if ($email === '') {
                Log::channel('payments')->warning('free_reservation_request_rejected_email_skipped', [
                    'event' => 'free_reservation_request_rejected_email_skipped',
                    'free_reservation_request_id' => (int) $request->id,
                    'reason' => 'empty_recipient',
                ]);

                return null;
            }

            return [
                'email' => $email,
                'locale' => $request->locale === 'en' ? 'en' : 'cg',
                'name' => (string) $request->institution_name,
                'request_id' => (int) $request->id,
                'date' => $request->reservation_date?->format('d.m.Y.') ?? '',
                'reason' => (string) $request->rejection_reason,
            ];
        });

        if ($payload === null) {
            return;
        }

        try {
            Mail::to($payload['email'])->send(new FreeReservationRequestRejectedMail(
                agencyLocale: $payload['locale'],
                institutionName: $payload['name'],
                requestId: $payload['request_id'],
                reservationDate: $payload['date'],
                rejectionReason: $payload['reason'],
            ));

            FreeReservationRequest::query()
                ->whereKey($payload['request_id'])
                ->whereNull('rejection_notification_sent_at')
                ->update(['rejection_notification_sent_at' => now()]);

            Log::channel('payments')->info('free_reservation_request_rejected_email_sent', [
                'event' => 'free_reservation_request_rejected_email_sent',
                'free_reservation_request_id' => $payload['request_id'],
                'recipient_email' => $payload['email'],
            ]);
        } catch (Throwable $e) {
            Log::channel('payments')->warning('free_reservation_request_rejected_email_failed', [
                'event' => 'free_reservation_request_rejected_email_failed',
                'free_reservation_request_id' => $payload['request_id'],
                'recipient_email' => $payload['email'],
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            throw $e;
        }
    }
}
