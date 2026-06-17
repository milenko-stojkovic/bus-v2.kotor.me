<?php

namespace Tests\Feature\Fiscal;

use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\SystemConfig;
use App\Models\VehicleType;
use App\Services\FiscalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FiscalReceiptPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_driver_sends_primatech_receipt_shape(): void
    {
        config([
            'services.fiscalization.driver' => 'real',
            'services.fiscal.api_url' => 'https://fiscal.test',
            'services.fiscal.api_token' => 'token',
            'services.fiscal.enu_identifier' => 'ENU-TEST',
            'services.fiscal.user_code' => 'OP-TEST',
            'services.fiscal.user_name' => 'Operator Test',
            'services.fiscal.seller_name' => 'Opština Kotor',
            'services.fiscal.seller_id_type' => 'TIN',
            'services.fiscal.seller_id_value' => '02012936',
            'services.fiscal.seller_address' => 'Kotor',
            'services.fiscal.tax_rate' => 0,
        ]);

        SystemConfig::query()->updateOrInsert(
            ['name' => 'document_number'],
            ['value' => 42, 'updated_at' => now()]
        );

        $mtid = (string) Str::uuid();

        Http::fake([
            'https://fiscal.test/api/efiscal/deposit' => Http::response(['IsSucccess' => true]),
            'https://fiscal.test/api/efiscal/fiscalReceipt' => Http::response([
                'IsSucccess' => true,
                'ResponseCode' => 'JIKR-TEST',
                'UIDRequest' => 'IKOF-TEST',
                'Url' => ['Value' => 'https://efitest.tax.gov.me/ic/#/verify?iic=IKOF-TEST'],
            ]),
        ]);

        $reservation = $this->makePaidReservation($mtid);

        $result = app(FiscalizationService::class)->tryFiscalize($reservation);

        $this->assertSame('JIKR-TEST', $result['fiscal_jir'] ?? null);

        Http::assertSent(function ($request) use ($mtid) {
            if (! str_contains($request->url(), '/api/efiscal/fiscalReceipt')) {
                return false;
            }

            $body = $request->data();

            return ($body['UID'] ?? null) === $mtid
                && ($body['DocumentType'] ?? null) === 'INVOICE'
                && ($body['DocumentNumber'] ?? null) === 42
                && ($body['IsNoCashReceipt'] ?? null) === false
                && ($body['Payments']['PaymentRow'][0]['PaymentType'] ?? null) === 'CARD'
                && ($body['Sales']['ItemSaleRow'][0]['ItemName'] ?? '') !== ''
                && ! isset($body['Buyer'])
                && isset($body['Seller']['IDValue']);
        });

        Http::assertSent(function ($request) use ($mtid) {
            if (! str_contains($request->url(), '/api/efiscal/deposit')) {
                return false;
            }

            $body = $request->data();

            return ($body['UID'] ?? null) === $mtid
                && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($body['DateSend'] ?? '')) === 1;
        });
    }

    private function makePaidReservation(string $merchantTransactionId): Reservation
    {
        $drop = ListOfTimeSlot::query()->firstOrCreate(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->firstOrCreate(['time_slot' => '12:00 - 12:20']);
        $vt = VehicleType::query()->first() ?? VehicleType::query()->create(['price' => 15.00]);

        return Reservation::query()->create([
            'merchant_transaction_id' => $merchantTransactionId,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => now()->toDateString(),
            'user_name' => 'Test Kupac',
            'country' => 'ME',
            'license_plate' => 'KO-TEST-1',
            'vehicle_type_id' => $vt->id,
            'email' => 'kupac@example.com',
            'payment_method' => 'card',
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
    }
}
