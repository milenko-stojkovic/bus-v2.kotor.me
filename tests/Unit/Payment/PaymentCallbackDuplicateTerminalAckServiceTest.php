<?php

namespace Tests\Unit\Payment;

use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\VehicleType;
use App\Services\Payment\PaymentCallbackDuplicateTerminalAckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentCallbackDuplicateTerminalAckServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentCallbackDuplicateTerminalAckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PaymentCallbackDuplicateTerminalAckService;
    }

    public function test_returns_null_for_non_success_status(): void
    {
        $this->seedProcessedWithReservation('mt-unit-fail');

        $this->assertNull($this->service->contextForImmediateAck('mt-unit-fail', 'failed'));
    }

    public function test_returns_null_for_pending_temp(): void
    {
        $slot = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $vt = VehicleType::query()->create(['price' => 10]);
        TempData::query()->create([
            'merchant_transaction_id' => 'mt-unit-pending',
            'retry_token' => 'rt',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => now()->addDay()->toDateString(),
            'user_name' => 'X',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $vt->id,
            'email' => 'x@example.com',
            'status' => TempData::STATUS_PENDING,
        ]);

        $this->assertNull($this->service->contextForImmediateAck('mt-unit-pending', 'success'));
    }

    public function test_returns_context_for_processed_with_reservation(): void
    {
        $seed = $this->seedProcessedWithReservation('mt-unit-ok');

        $context = $this->service->contextForImmediateAck('mt-unit-ok', 'success');

        $this->assertIsArray($context);
        $this->assertSame((int) $seed['temp']->id, $context['temp_data_id']);
        $this->assertSame((int) $seed['reservation']->id, $context['reservation_id']);
        $this->assertSame(TempData::STATUS_PROCESSED, $context['temp_status']);
    }

    /**
     * @return array{temp: TempData, reservation: Reservation}
     */
    private function seedProcessedWithReservation(string $mtid): array
    {
        $slot = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $vt = VehicleType::query()->create(['price' => 10]);

        $temp = TempData::query()->create([
            'merchant_transaction_id' => $mtid,
            'retry_token' => 'rt',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => now()->addDay()->toDateString(),
            'user_name' => 'X',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $vt->id,
            'email' => 'x@example.com',
            'status' => TempData::STATUS_PROCESSED,
        ]);

        $reservation = Reservation::query()->create([
            'merchant_transaction_id' => $mtid,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => $temp->reservation_date,
            'user_name' => 'X',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $vt->id,
            'email' => 'x@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_SENT,
        ]);

        return ['temp' => $temp, 'reservation' => $reservation];
    }
}
