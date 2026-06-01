<?php

namespace App\Services\Payment;

use App\Jobs\ProcessReservationAfterPaymentJob;
use App\Jobs\SendFreeReservationConfirmationJob;
use App\Models\DailyParkingData;
use App\Models\Reservation;
use App\Models\TempData;
use App\Services\AdminPanel\Blocking\BlockZoneWorklistService;
use App\Support\QueueMode;
use App\Support\ReservationInvoiceAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Zajednički flow za SUCCESS plaćanja: callback ili status inquiry (timeout callback).
 * Jedna transakcija: lock temp_data, provera rezervacije, kreiranje rezervacije, temp → processed, release soft-lock, dispatch ProcessReservationAfterPaymentJob.
 * Besplatna rezervacija (checkout): ista transakcija, status rezervacije `free`, bez fiskalizacije — samo SendFreeReservationConfirmationJob.
 * Ako lock nije validan → prelaz u late_success (bez kreiranja rezervacije).
 */
class PaymentSuccessHandler
{
    /**
     * Primeni SUCCESS: kreira rezervaciju, temp_data → processed, oslobodi lock.
     * Ako je $runFiscalAndInvoicePipeline true (plaćeno): ProcessReservationAfterPaymentJob (fiskalizacija, PDF, mail sa računom).
     * Ako je false (besplatno): status `free`, samo potvrda emailom bez računa / fiskalizacije.
     *
     * Ako lock nije validan, prelazi u late_success i vraća false.
     *
     * @param  bool  $deferFakeBankFiscalPipeline  Kada su banka i fiskal fake: true = ne šalji ProcessReservation… (forma u FakeBankCompleteController šalje sa scenarijem); false = webhook/async callback, pipeline ovde.
     * @return bool true ako je rezervacija kreirana, false inače (late_success ili već postoji)
     */
    public function handle(TempData $temp, array $rawPayload, bool $runFiscalAndInvoicePipeline = true, bool $deferFakeBankFiscalPipeline = false): bool
    {
        if (! $temp->isLockValidForProcessed()) {
            $this->transitionToLateSuccess($temp, false, $rawPayload);

            return false;
        }

        $created = false;
        DB::transaction(function () use ($temp, $rawPayload, $runFiscalAndInvoicePipeline, $deferFakeBankFiscalPipeline, &$created): void {
            $temp = TempData::where('merchant_transaction_id', $temp->merchant_transaction_id)->lockForUpdate()->first();
            if (! $temp || Reservation::where('merchant_transaction_id', $temp->merchant_transaction_id)->exists()) {
                return;
            }
            if (! $temp->isLockValidForProcessed()) {
                $from = $temp->status;
                $temp->update([
                    'status' => TempData::STATUS_LATE_SUCCESS,
                    'raw_callback_payload' => $rawPayload,
                ]);
                TempData::logStateTransition($temp->merchant_transaction_id, $from, TempData::STATUS_LATE_SUCCESS, 'SUCCESS but lock no longer valid');

                return;
            }

            $reservationStatus = $runFiscalAndInvoicePipeline ? 'paid' : 'free';
            $reservation = $this->createReservationFromTempData($temp, $reservationStatus);
            Log::channel('payments')->info('payment_reservation_created', [
                'reservation_id' => $reservation->id,
                'merchant_transaction_id' => $reservation->merchant_transaction_id,
                'user_id' => $reservation->user_id,
                'status' => $reservation->status,
            ]);
            app(BlockZoneWorklistService::class)->onReservationCreated($reservation, $temp);
            $from = $temp->status;
            $temp->update([
                'status' => TempData::STATUS_PROCESSED,
                'raw_callback_payload' => $rawPayload,
            ]);
            TempData::logStateTransition($temp->merchant_transaction_id, $from, TempData::STATUS_PROCESSED, 'SUCCESS');
            $this->doReleaseSoftLock($temp, true);
            if ($runFiscalAndInvoicePipeline) {
                $bankFake = (config('services.bank.driver') ?? config('payment.provider', 'fake')) === 'fake';
                $fiscalFake = config('services.fiscalization.driver') === 'fake';
                if ($bankFake && $fiscalFake) {
                    if (! $deferFakeBankFiscalPipeline) {
                        QueueMode::dispatchForFakeE2e(new ProcessReservationAfterPaymentJob($reservation->id));
                    }
                } else {
                    // Real bank ili real fiskal: uvijek red (FAKE_PAYMENT_E2E_SYNC se ne primjenjuje na miješane tokove).
                    ProcessReservationAfterPaymentJob::dispatch($reservation->id);
                }
            } else {
                SendFreeReservationConfirmationJob::dispatch($reservation->id);
            }
            $created = true;
        });

        return $created;
    }

    /**
     * Prelaz u late_success (callback timeout ili SUCCESS ali lock nije validan). Koristi PaymentCallbackJob za status 'timeout'.
     */
    public function applyLateSuccess(TempData $temp, array $rawPayload, bool $releaseLock): void
    {
        $this->transitionToLateSuccess($temp, $releaseLock, $rawPayload);
    }

    private function transitionToLateSuccess(TempData $temp, bool $releaseLock, array $rawPayload): void
    {
        DB::transaction(function () use ($temp, $releaseLock, $rawPayload): void {
            $temp = TempData::where('merchant_transaction_id', $temp->merchant_transaction_id)->lockForUpdate()->first();
            if (! $temp || $temp->status === TempData::STATUS_LATE_SUCCESS) {
                return;
            }
            $from = $temp->status;
            $temp->update([
                'status' => TempData::STATUS_LATE_SUCCESS,
                'raw_callback_payload' => $rawPayload,
            ]);
            TempData::logStateTransition($temp->merchant_transaction_id, $from, TempData::STATUS_LATE_SUCCESS, 'SUCCESS but lock expired/canceled or timeout');
            if ($releaseLock) {
                $this->doReleaseSoftLock($temp, false);
            }
        });
    }

    /** Javno za PaymentCallbackJob handleCanceled (oslobađanje lock-a bez increment reserved). */
    public function releaseSoftLock(TempData $temp, bool $incrementReserved): void
    {
        $this->doReleaseSoftLock($temp, $incrementReserved);
    }

    private function doReleaseSoftLock(TempData $temp, bool $incrementReserved): void
    {
        if ($temp->isDailyTicket()) {
            return;
        }

        $slotIds = array_values(array_unique(array_filter([
            $temp->drop_off_time_slot_id,
            $temp->pick_up_time_slot_id,
        ], fn ($id) => $id !== null)));

        if ($slotIds === []) {
            return;
        }

        $rows = DailyParkingData::query()
            ->where('date', $temp->reservation_date)
            ->whereIn('time_slot_id', $slotIds)
            ->get();

        foreach ($rows as $daily) {
            $daily->decrement('pending');
            if ($incrementReserved) {
                $daily->increment('reserved');
            }
        }
    }

    private function createReservationFromTempData(TempData $temp, string $status = 'paid'): Reservation
    {
        return Reservation::create([
            'user_id' => $temp->user_id,
            'vehicle_id' => $temp->vehicle_id,
            'merchant_transaction_id' => $temp->merchant_transaction_id,
            'reservation_kind' => $temp->reservation_kind ?? Reservation::KIND_TIME_SLOTS,
            'drop_off_time_slot_id' => $temp->drop_off_time_slot_id,
            'pick_up_time_slot_id' => $temp->pick_up_time_slot_id,
            'reservation_date' => $temp->reservation_date,
            'user_name' => $temp->user_name,
            'country' => $temp->country,
            'license_plate' => $temp->license_plate,
            'vehicle_type_id' => $temp->vehicle_type_id,
            'email' => $temp->email,
            'preferred_locale' => $temp->preferred_locale,
            'status' => $status,
            'invoice_amount' => ReservationInvoiceAmount::snapshotForNewReservation($status, $temp->vehicle_type_id),
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
        ]);
    }
}
