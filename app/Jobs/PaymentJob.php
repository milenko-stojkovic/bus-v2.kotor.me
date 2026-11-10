<?php

namespace App\Jobs;

use App\Contracts\PaymentResult;
use App\Contracts\PaymentService;
use App\Models\Reservation;
use App\Models\TempData;
use App\Support\ReservationInvoiceAmount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Async payment job. Idempotentan: merchant_transaction_id je idempotency key – ponovni pokušaji ne smeju duplirati rezervacije.
 * Controller samo dispatch-uje ovaj job; nikad ne poziva gateway direktno.
 * State machine: pending → success (reservation created) | failed | timeout → late_success.
 */
class PaymentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function __construct(
        public int $tempDataId
    ) {}

    public function handle(PaymentService $paymentService): void
    {
        $temp = TempData::find($this->tempDataId);
        if (! $temp) {
            return;
        }

        $idempotencyKey = $temp->merchant_transaction_id;
        if (Reservation::where('merchant_transaction_id', $idempotencyKey)->exists()) {
            return;
        }

        if ($temp->status !== TempData::STATUS_PENDING) {
            return;
        }

        $result = $paymentService->pay($temp);

        if ($result->isSuccess()) {
            $reservation = $this->createReservationFromTempData($temp);
            $temp->update(['status' => TempData::STATUS_PROCESSED]);
            ProcessReservationAfterPaymentJob::dispatch($reservation->id);
            return;
        }

        if ($result->isFailed()) {
            $temp->update(['status' => TempData::STATUS_CANCELED]);
            return;
        }

        if ($result->isTimeout()) {
            $temp->update(['status' => TempData::STATUS_LATE_SUCCESS]);
        }
    }

    public function uniqueId(): string
    {
        $temp = TempData::find($this->tempDataId);

        return $temp?->merchant_transaction_id ?? (string) $this->tempDataId;
    }

    public function failed(?Throwable $e): void
    {
        $temp = TempData::find($this->tempDataId);
        Log::channel('payments')->error('payment_job_exhausted', [
            'temp_data_id' => $this->tempDataId,
            'merchant_transaction_id' => $temp?->merchant_transaction_id,
            'reservation_id' => $temp !== null
                ? Reservation::query()->where('merchant_transaction_id', $temp->merchant_transaction_id)->value('id')
                : null,
            'message' => $e?->getMessage(),
            'exception' => $e !== null ? $e::class : null,
        ]);
    }

    private function createReservationFromTempData(TempData $temp): Reservation
    {
        return Reservation::create([
            'user_id' => $temp->user_id,
            'vehicle_id' => null,
            'merchant_transaction_id' => $temp->merchant_transaction_id,
            'drop_off_time_slot_id' => $temp->drop_off_time_slot_id,
            'pick_up_time_slot_id' => $temp->pick_up_time_slot_id,
            'reservation_date' => $temp->reservation_date,
            'user_name' => $temp->user_name,
            'country' => $temp->country,
            'license_plate' => $temp->license_plate,
            'vehicle_type_id' => $temp->vehicle_type_id,
            'email' => $temp->email,
            'status' => 'paid',
            'invoice_amount' => ReservationInvoiceAmount::forPaymentAttempt($temp, 'paid'),
            'email_sent' => \App\Models\Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
        ]);
    }
}
