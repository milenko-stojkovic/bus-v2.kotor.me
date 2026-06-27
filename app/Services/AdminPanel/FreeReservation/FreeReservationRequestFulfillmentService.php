<?php

namespace App\Services\AdminPanel\FreeReservation;

use App\Exceptions\AdminFreeReservationSlotsUnavailableException;
use App\Exceptions\AmbiguousFreeReservationLinkException;
use App\Exceptions\DuplicateTerminiReservationException;
use App\Exceptions\FreeReservationLinkedToOtherRequestException;
use App\Mail\FreeReservationRequestFulfilledMail;
use App\Models\AdminAlert;
use App\Models\DailyParkingData;
use App\Models\FreeReservationRequest;
use App\Models\Reservation;
use App\Services\Pdf\FreeReservationPdfGenerator;
use App\Services\Reservation\DuplicateReservationAttemptService;
use App\Support\ReservationEmailReferenceLine;
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
        private FreeReservationRequestReservationMatcher $matcher,
    ) {}

    /**
     * Creates or links admin-free reservations per vehicle (idempotent).
     *
     * @return array{
     *     reservations: list<Reservation>,
     *     mail_sent: bool,
     *     mail_skipped_already_sent: bool,
     *     idempotent: bool,
     *     linked_existing: int,
     *     created_new: int
     * }
     *
     * @throws AdminFreeReservationSlotsUnavailableException
     * @throws AmbiguousFreeReservationLinkException
     * @throws DuplicateTerminiReservationException
     * @throws FreeReservationLinkedToOtherRequestException
     */
    public function fulfill(FreeReservationRequest $req): array
    {
        $req->loadMissing(['segments.vehicles']);

        if ($req->status === FreeReservationRequest::STATUS_FULFILLED) {
            return $this->completeFulfilledRequest($req, idempotent: true);
        }

        if (! in_array($req->status, [
            FreeReservationRequest::STATUS_SUBMITTED,
            FreeReservationRequest::STATUS_UPDATED,
        ], true)) {
            throw new AdminFreeReservationSlotsUnavailableException;
        }

        if ((int) $req->segments->count() < 1) {
            throw new AdminFreeReservationSlotsUnavailableException;
        }

        $plan = $this->matcher->buildPlan($req, allowRelinkFromWrongRequest: true);

        if ($plan === []) {
            throw new AdminFreeReservationSlotsUnavailableException;
        }

        $demandByDateSlot = $this->demandForCreates($plan);

        /** @var list<Reservation> $reservations */
        $reservations = DB::transaction(function () use ($req, $plan, $demandByDateSlot): array {
            $this->lockCapacityForCreates($demandByDateSlot);

            $out = [];
            $linkedExisting = 0;
            $createdNew = 0;
            $excludeIds = [];

            foreach ($plan as $item) {
                $line = $item['line'];
                $action = $item['action'];

                if ($action === 'use_linked' && $item['reservation'] instanceof Reservation) {
                    $out[] = $item['reservation'];
                    $excludeIds[] = (int) $item['reservation']->id;

                    continue;
                }

                if ($action === 'link' && $item['reservation'] instanceof Reservation) {
                    $reservation = $item['reservation'];
                    $reservation->update(['free_reservation_request_id' => $req->id]);
                    $reservation->refresh();
                    $out[] = $reservation;
                    $excludeIds[] = (int) $reservation->id;
                    $linkedExisting++;

                    continue;
                }

                $this->duplicateReservationAttemptService->assertNoConflict(
                    $line['date'],
                    $line['license_plate'],
                    (int) $line['drop_off_time_slot_id'],
                    (int) $line['pick_up_time_slot_id'],
                    exceptReservationIds: $excludeIds,
                );

                $mtid = Str::uuid()->toString();
                $reservation = Reservation::query()->create([
                    'free_reservation_request_id' => $req->id,
                    'user_id' => null,
                    'vehicle_id' => null,
                    'merchant_transaction_id' => $mtid,
                    'drop_off_time_slot_id' => (int) $line['drop_off_time_slot_id'],
                    'pick_up_time_slot_id' => (int) $line['pick_up_time_slot_id'],
                    'reservation_date' => $line['date'],
                    'user_name' => $req->institution_name,
                    'country' => $req->country,
                    'license_plate' => $line['license_plate'],
                    'vehicle_type_id' => (int) $line['vehicle_type_id'],
                    'email' => $req->institution_email,
                    'preferred_locale' => $req->locale,
                    'status' => 'free',
                    'invoice_amount' => ReservationInvoiceAmount::snapshotForNewReservation('free', (int) $line['vehicle_type_id']),
                    'email_sent' => Reservation::EMAIL_NOT_SENT,
                    'created_by_admin' => true,
                ]);
                $this->blockZoneWorklistService->onReservationCreated($reservation, null);
                $out[] = $reservation;
                $excludeIds[] = (int) $reservation->id;
                $createdNew++;
            }

            foreach ($demandByDateSlot as $date => $demandBySlotId) {
                foreach ($demandBySlotId as $slotId => $demand) {
                    if ($demand < 1) {
                        continue;
                    }
                    DailyParkingData::query()
                        ->whereDate('date', $date)
                        ->where('time_slot_id', (int) $slotId)
                        ->increment('reserved', (int) $demand);
                }
            }

            $this->markRequestFulfilled($req);

            return $out;
        });

        $linkedExisting = (int) collect($plan)->where('action', 'link')->count();
        $createdNew = (int) collect($plan)->where('action', 'create')->count();
        $idempotent = $linkedExisting > 0 && $createdNew === 0;

        $mailResult = $this->sendMultiConfirmationEmail($req, $reservations);

        return [
            'reservations' => $reservations,
            'mail_sent' => $mailResult['sent'],
            'mail_skipped_already_sent' => $mailResult['skipped_already_sent'],
            'idempotent' => $idempotent,
            'linked_existing' => $linkedExisting,
            'created_new' => $createdNew,
        ];
    }

    /**
     * @return array{
     *     reservations: list<Reservation>,
     *     mail_sent: bool,
     *     mail_skipped_already_sent: bool,
     *     idempotent: bool,
     *     linked_existing: int,
     *     created_new: int
     * }
     */
    public function repairSubmittedRequest(FreeReservationRequest $req, bool $dryRun = false): array
    {
        $req->loadMissing(['segments.vehicles']);

        if (! in_array($req->status, [
            FreeReservationRequest::STATUS_SUBMITTED,
            FreeReservationRequest::STATUS_UPDATED,
        ], true)) {
            return [
                'reservations' => [],
                'mail_sent' => false,
                'mail_skipped_already_sent' => false,
                'idempotent' => false,
                'linked_existing' => 0,
                'created_new' => 0,
            ];
        }

        $plan = $this->matcher->buildPlan($req, allowRelinkFromWrongRequest: true);
        $needsWork = collect($plan)->contains(fn (array $item): bool => $item['action'] !== 'use_linked');

        if (! $needsWork) {
            if ($dryRun) {
                return [
                    'reservations' => collect($plan)->pluck('reservation')->filter()->values()->all(),
                    'mail_sent' => false,
                    'mail_skipped_already_sent' => false,
                    'idempotent' => true,
                    'linked_existing' => 0,
                    'created_new' => 0,
                ];
            }

            return $this->fulfill($req);
        }

        if ($dryRun) {
            return [
                'reservations' => [],
                'mail_sent' => false,
                'mail_skipped_already_sent' => false,
                'idempotent' => false,
                'linked_existing' => (int) collect($plan)->where('action', 'link')->count(),
                'created_new' => (int) collect($plan)->where('action', 'create')->count(),
            ];
        }

        return $this->fulfill($req);
    }

    /**
     * Send (or resend) fulfillment confirmation for an already fulfilled request.
     *
     * @return array{
     *     reservations: list<Reservation>,
     *     mail_sent: bool,
     *     mail_skipped_already_sent: bool,
     *     would_send?: bool
     * }
     */
    public function repairFulfilledRequest(FreeReservationRequest $req, bool $dryRun = false, bool $forceResend = false): array
    {
        $req->loadMissing(['segments.vehicles']);

        if ($req->status !== FreeReservationRequest::STATUS_FULFILLED) {
            return [
                'reservations' => [],
                'mail_sent' => false,
                'mail_skipped_already_sent' => false,
            ];
        }

        $reservations = $req->reservations()->get()->all();
        if ($reservations === []) {
            return [
                'reservations' => [],
                'mail_sent' => false,
                'mail_skipped_already_sent' => false,
            ];
        }

        $needsSend = $forceResend || collect($reservations)->contains(
            fn (Reservation $r): bool => (int) $r->email_sent === Reservation::EMAIL_NOT_SENT
        );

        if (! $needsSend) {
            return [
                'reservations' => $reservations,
                'mail_sent' => false,
                'mail_skipped_already_sent' => true,
            ];
        }

        if ($dryRun) {
            return [
                'reservations' => $reservations,
                'mail_sent' => false,
                'mail_skipped_already_sent' => false,
                'would_send' => true,
            ];
        }

        $mailResult = $this->sendMultiConfirmationEmail($req, $reservations, $forceResend);

        return [
            'reservations' => $reservations,
            'mail_sent' => $mailResult['sent'],
            'mail_skipped_already_sent' => $mailResult['skipped_already_sent'],
        ];
    }

    /**
     * @param  list<array{line: array, action: string, reservation: Reservation|null}>  $plan
     * @return array<string, array<int, int>>
     */
    private function demandForCreates(array $plan): array
    {
        $demandByDateSlot = [];

        foreach ($plan as $item) {
            if ($item['action'] !== 'create') {
                continue;
            }

            $line = $item['line'];
            $date = $line['date'];
            $slotIds = array_values(array_unique([
                (int) $line['drop_off_time_slot_id'],
                (int) $line['pick_up_time_slot_id'],
            ]));

            foreach ($slotIds as $slotId) {
                $demandByDateSlot[$date][$slotId] = (int) ($demandByDateSlot[$date][$slotId] ?? 0) + 1;
            }
        }

        return $demandByDateSlot;
    }

    /**
     * @param  array<string, array<int, int>>  $demandByDateSlot
     */
    private function lockCapacityForCreates(array $demandByDateSlot): void
    {
        foreach ($demandByDateSlot as $date => $demandBySlotId) {
            $slotIds = array_values(array_unique(array_map('intval', array_keys($demandBySlotId))));
            sort($slotIds);

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
            }
        }
    }

    /**
     * @return array{
     *     reservations: list<Reservation>,
     *     mail_sent: bool,
     *     mail_skipped_already_sent: bool,
     *     idempotent: bool,
     *     linked_existing: int,
     *     created_new: int
     * }
     */
    private function completeFulfilledRequest(FreeReservationRequest $req, bool $idempotent): array
    {
        $reservations = $req->reservations()->get()->all();
        if ($reservations === []) {
            $plan = $this->matcher->buildPlan($req, allowRelinkFromWrongRequest: true);
            $reservations = DB::transaction(function () use ($req, $plan): array {
                $out = [];
                foreach ($plan as $item) {
                    if (! $item['reservation'] instanceof Reservation) {
                        continue;
                    }
                    if ($item['action'] === 'link') {
                        $item['reservation']->update(['free_reservation_request_id' => $req->id]);
                        $item['reservation']->refresh();
                    }
                    $out[] = $item['reservation'];
                }
                $this->markRequestFulfilled($req);

                return $out;
            });
        }

        $mailResult = $this->sendMultiConfirmationEmail($req, $reservations);

        return [
            'reservations' => $reservations,
            'mail_sent' => $mailResult['sent'],
            'mail_skipped_already_sent' => $mailResult['skipped_already_sent'],
            'idempotent' => $idempotent,
            'linked_existing' => 0,
            'created_new' => 0,
        ];
    }

    private function markRequestFulfilled(FreeReservationRequest $req): void
    {
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

    /**
     * @param  list<Reservation>  $reservations
     * @return array{sent: bool, skipped_already_sent: bool}
     */
    private function sendMultiConfirmationEmail(FreeReservationRequest $req, array $reservations, bool $forceResend = false): array
    {
        if ($reservations === []) {
            return ['sent' => false, 'skipped_already_sent' => false];
        }

        $reservationIds = collect($reservations)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $needsSend = $forceResend || collect($reservations)->contains(
            fn (Reservation $r): bool => (int) $r->email_sent === Reservation::EMAIL_NOT_SENT
        );
        if (! $needsSend) {
            Log::channel('payments')->info('free_reservation_request_multi_email_skipped_already_sent', [
                'free_reservation_request_id' => $req->id,
                'reservation_ids' => $reservationIds,
                'email' => $req->institution_email,
            ]);

            return ['sent' => false, 'skipped_already_sent' => true];
        }

        $email = $req->institution_email;
        if (! is_string($email) || trim($email) === '') {
            throw new RuntimeException('Missing institution_email for free reservation request.');
        }

        $emailLocale = in_array($req->locale, ['cg', 'en'], true) ? $req->locale : 'cg';
        $previousLocale = app()->getLocale();
        app()->setLocale($emailLocale);

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
        $body = ReservationEmailReferenceLine::appendBeforeClosing(
            $body,
            ReservationEmailReferenceLine::forReservations($reservations, $emailLocale),
        );

        $attachmentFilenames = array_map(
            fn (Reservation $r): string => $r->freeConfirmationPdfFilename(),
            $reservations,
        );

        Log::channel('payments')->info('free_reservation_request_multi_email_started', [
            'event' => 'free_reservation_request_multi_email_started',
            'free_reservation_request_id' => $req->id,
            'reservation_ids' => $reservationIds,
            'recipient_email' => $email,
            'count' => count($reservations),
            'attachment_filenames' => $attachmentFilenames,
        ]);

        $tmpPaths = [];
        try {
            $pdfAttachments = [];
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
                $tmpPaths[] = $tmp;
                $pdfAttachments[] = [
                    'path' => $tmp,
                    'filename' => $r->freeConfirmationPdfFilename(),
                ];
            }

            Mail::to($email)->send(new FreeReservationRequestFulfilledMail($body, $subject, $pdfAttachments));

            foreach ($reservations as $r) {
                if ((int) $r->email_sent === Reservation::EMAIL_NOT_SENT) {
                    $r->markConfirmationEmailSent();
                }
            }

            Log::channel('payments')->info('free_reservation_request_multi_email_sent', [
                'event' => 'free_reservation_request_multi_email_sent',
                'free_reservation_request_id' => $req->id,
                'reservation_ids' => $reservationIds,
                'recipient_email' => $email,
                'count' => count($reservations),
                'attachment_filenames' => $attachmentFilenames,
            ]);

            return ['sent' => true, 'skipped_already_sent' => false];
        } catch (Throwable $e) {
            Log::channel('payments')->warning('free_reservation_request_multi_email_failed', [
                'event' => 'free_reservation_request_multi_email_failed',
                'free_reservation_request_id' => $req->id,
                'reservation_ids' => $reservationIds,
                'recipient_email' => $email,
                'count' => count($reservations),
                'attachment_filenames' => $attachmentFilenames ?? [],
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return ['sent' => false, 'skipped_already_sent' => false];
        } finally {
            foreach ($tmpPaths as $path) {
                if (is_string($path)) {
                    @unlink($path);
                }
            }
            app()->setLocale($previousLocale);
        }
    }
}
