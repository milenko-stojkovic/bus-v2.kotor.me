<?php

namespace App\Services\AdminPanel\FreeReservation;

use App\Exceptions\AdminFreeReservationSlotsUnavailableException;
use App\Models\AdminAlert;
use App\Models\DailyParkingData;
use App\Models\FreeReservationRequest;
use App\Models\Reservation;
use App\Services\Pdf\FreeReservationPdfGenerator;
use App\Services\Reservation\DuplicateReservationAttemptService;
use App\Support\ReservationInvoiceAmount;
use App\Support\UiText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FreeReservationRequestFulfillmentService
{
    public function __construct(
        private FreeReservationPdfGenerator $pdfGenerator,
        private \App\Services\AdminPanel\Blocking\BlockZoneWorklistService $blockZoneWorklistService,
        private DuplicateReservationAttemptService $duplicateReservationAttemptService,
    ) {}

    /**
     * Creates one admin-free reservation per vehicle (all-or-nothing on availability).
     *
     * @return array{created: list<Reservation>, mail_sent: bool}
     *
     * @throws AdminFreeReservationSlotsUnavailableException
     */
    public function fulfill(FreeReservationRequest $req): array
    {
        $req->loadMissing(['segments.vehicles']);
        if ((int) $req->segments->count() < 1) {
            throw new AdminFreeReservationSlotsUnavailableException;
        }

        $date = $req->reservation_date?->toDateString() ?? '';
        if ($date === '') {
            throw new AdminFreeReservationSlotsUnavailableException;
        }

        // Demand per slot across all segments (so shared slots are validated correctly).
        $demandBySlotId = [];
        foreach ($req->segments as $seg) {
            $required = (int) $seg->vehicles->count();
            if ($required < 1) {
                throw new AdminFreeReservationSlotsUnavailableException;
            }
            $slotIds = array_values(array_unique([(int) $seg->drop_off_time_slot_id, (int) $seg->pick_up_time_slot_id]));
            foreach ($slotIds as $slotId) {
                $demandBySlotId[$slotId] = (int) ($demandBySlotId[$slotId] ?? 0) + $required;
            }
        }

        $slotIds = array_values(array_unique(array_map('intval', array_keys($demandBySlotId))));
        sort($slotIds);

        /** @var list<Reservation> $created */
        $created = DB::transaction(function () use ($req, $date, $slotIds, $demandBySlotId): array {
            /** @var array<int, DailyParkingData> $locked */
            $locked = [];
            foreach ($slotIds as $slotId) {
                $row = DailyParkingData::query()
                    ->whereDate('date', $date)
                    ->where('time_slot_id', $slotId)
                    ->lockForUpdate()
                    ->first();
                $demand = (int) ($demandBySlotId[$slotId] ?? 0);
                if ($row === null || $row->is_blocked || $row->availableCapacity() < $demand) {
                    throw new AdminFreeReservationSlotsUnavailableException;
                }
                $locked[$slotId] = $row;
            }

            $out = [];
            foreach ($req->segments as $seg) {
                $drop = (int) $seg->drop_off_time_slot_id;
                $pick = (int) $seg->pick_up_time_slot_id;

                foreach ($seg->vehicles as $v) {
                    $this->duplicateReservationAttemptService->assertNoConflict(
                        $date,
                        (string) $v->license_plate,
                        $drop,
                        $pick,
                    );

                    $mtid = Str::uuid()->toString();
                    $reservation = Reservation::query()->create([
                        'free_reservation_request_id' => $req->id,
                        'user_id' => null,
                        'vehicle_id' => null,
                        'merchant_transaction_id' => $mtid,
                        'drop_off_time_slot_id' => $drop,
                        'pick_up_time_slot_id' => $pick,
                        'reservation_date' => $date,
                        'user_name' => $req->institution_name,
                        'country' => $req->country,
                        'license_plate' => $v->license_plate,
                        'vehicle_type_id' => (int) $v->vehicle_type_id,
                        'email' => $req->institution_email,
                        'preferred_locale' => $req->locale,
                        'status' => 'free',
                        'invoice_amount' => ReservationInvoiceAmount::snapshotForNewReservation('free', (int) $v->vehicle_type_id),
                        'email_sent' => Reservation::EMAIL_NOT_SENT,
                        'created_by_admin' => true,
                    ]);
                    $this->blockZoneWorklistService->onReservationCreated($reservation, null);
                    $out[] = $reservation;
                }
            }

            foreach ($slotIds as $slotId) {
                $locked[$slotId]->increment('reserved', (int) ($demandBySlotId[$slotId] ?? 0));
            }

            return $out;
        });

        // Mail with multiple PDF attachments (one per reservation).
        $mailSent = $this->sendMultiConfirmationEmail($req, $created);
        if ($mailSent) {
            $this->cleanupAfterSuccess($req);
        }

        return [
            'created' => $created,
            'mail_sent' => $mailSent,
        ];
    }

    private function sendMultiConfirmationEmail(FreeReservationRequest $req, array $reservations): bool
    {
        $email = $req->institution_email;
        if (! is_string($email) || trim($email) === '') {
            throw new RuntimeException('Missing institution_email for free reservation request.');
        }

        $emailLocale = in_array($req->locale, ['cg', 'en'], true) ? $req->locale : 'cg';
        $previousLocale = app()->getLocale();
        app()->setLocale($emailLocale);

        $fromAddress = config('mail.from.address');
        $fromName = config('mail.from.name');

        $subjectTemplate = UiText::t(
            'emails',
            'free_request_fulfilled_subject',
            'Zahtjev za besplatne rezervacije je obrađen (%1$d)',
            $emailLocale
        );
        $subject = sprintf($subjectTemplate, count($reservations));

        $bodyTemplate = UiText::t(
            'emails',
            'free_request_fulfilled_body',
            "Zdravo,\n\nVaš zahtjev je obrađen i potvrde besplatnih rezervacija su u prilogu.\nBroj vozila: %1\$d\nDatum: %2\$s\n\nHvala vam.",
            $emailLocale
        );
        $body = sprintf(
            $bodyTemplate,
            count($reservations),
            $req->reservation_date?->format('d.m.Y.') ?? ''
        );

        $tmpPaths = [];
        try {
            foreach ($reservations as $r) {
                $pdfBinary = $this->pdfGenerator->renderBinary($r);
                if ($pdfBinary === '') {
                    throw new RuntimeException('Free reservation PDF empty after renderBinary.');
                }
                $tmp = tempnam(sys_get_temp_dir(), 'bus_free_multi_');
                if ($tmp === false) {
                    throw new RuntimeException('tempnam failed for multi free reservation PDF attachment.');
                }
                file_put_contents($tmp, $pdfBinary);
                $tmpPaths[] = [$tmp, $r->freeConfirmationPdfFilename()];
            }

            Mail::raw($body, function ($message) use ($email, $fromAddress, $fromName, $subject, $tmpPaths): void {
                $message->to($email)
                    ->from($fromAddress, $fromName)
                    ->subject($subject);
                foreach ($tmpPaths as [$path, $filename]) {
                    $message->attach($path, [
                        'as' => $filename,
                        'mime' => 'application/pdf',
                    ]);
                }
            });

            // Isto kao SendFreeReservationConfirmationJob: jedan zajednički mejl pokriva sve redove u reservations.
            foreach ($reservations as $r) {
                $r->markConfirmationEmailSent();
            }

            Log::channel('payments')->info('free_reservation_request_multi_email_sent', [
                'free_reservation_request_id' => $req->id,
                'email' => $email,
                'count' => count($reservations),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::channel('payments')->warning('free_reservation_request_multi_email_failed', [
                'free_reservation_request_id' => $req->id,
                'email' => $email,
                'count' => count($reservations),
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            return false;
        } finally {
            foreach ($tmpPaths as [$path]) {
                if (is_string($path)) {
                    @unlink($path);
                }
            }
            app()->setLocale($previousLocale);
        }
    }

    private function cleanupAfterSuccess(FreeReservationRequest $req): void
    {
        // Remove warning pointers (if any), then mark request as fulfilled (keep record + snapshots + documents).
        AdminAlert::query()
            ->where('type', 'free_reservation_request')
            ->whereNull('removed_at')
            ->where('payload_json->free_reservation_request_id', $req->id)
            ->update([
                'status' => AdminAlert::STATUS_DONE,
                'resolved_at' => now(),
                'removed_at' => now(),
            ]);

        $req->update([
            'status' => FreeReservationRequest::STATUS_FULFILLED,
        ]);
    }
}

