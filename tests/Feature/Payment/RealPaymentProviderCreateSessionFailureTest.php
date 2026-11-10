<?php

namespace Tests\Feature\Payment;

use App\Models\ListOfTimeSlot;
use App\Models\TempData;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\Payment\RealPaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RealPaymentProviderCreateSessionFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejected_debit_does_not_return_raw_bank_message(): void
    {
        config([
            'services.bankart' => [
                'api_url' => 'https://bankart.test',
                'api_key' => 'api-key-1',
                'username' => 'user',
                'password' => 'pass',
                'shared_secret' => '',
                'signature_enabled' => false,
                'send_customer' => false,
            ],
        ]);

        Http::fake([
            'https://bankart.test/*' => Http::response([
                'code' => 2003,
                'message' => 'Enter lesser amount',
            ], 200),
        ]);

        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $vt = VehicleType::query()->create(['price' => 12.34]);
        foreach (['en', 'cg'] as $locale) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $locale,
                'name' => 'Car',
                'description' => null,
            ]);
        }

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mtid-create-session-fail-1',
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => now()->addDay()->toDateString(),
            'user_name' => 'T',
            'country' => 'ME',
            'license_plate' => 'AB123',
            'vehicle_type_id' => $vt->id,
            'invoice_amount_snapshot' => '12.34',
            'email' => 't@example.com',
            'status' => TempData::STATUS_PENDING,
        ]);
        $temp->load('vehicleType');

        $result = app(RealPaymentProvider::class)->createSession($temp);

        $this->assertFalse($result->success);
        $this->assertNull($result->paymentUrl);
        $this->assertNotNull($result->errorMessage);
        $this->assertStringNotContainsString('lesser', strtolower($result->errorMessage));
    }
}
