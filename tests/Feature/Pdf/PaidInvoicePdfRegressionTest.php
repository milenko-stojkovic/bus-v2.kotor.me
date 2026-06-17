<?php

namespace Tests\Feature\Pdf;

use App\Models\Admin;
use App\Models\LimoPickupEvent;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\Limo\LimoInvoiceAdapter;
use App\Services\Limo\LimoPickupService;
use App\Services\Pdf\KotorPdfAssets;
use App\Services\Pdf\PaidInvoicePdfGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regresija: sadržaj šablona `pdf.paid-invoice` za rezervacije vs limo (PDF binarni ne sadrži čitljiv UTF-8 tekst — testiramo isti HTML kao generator).
 */
final class PaidInvoicePdfRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_invoice_template_shows_reservation_details_not_limo(): void
    {
        $reservation = $this->makePaidReservationWithSlots();
        $html = $this->renderReservationInvoiceHtml($reservation, false);

        $this->assertStringContainsString('Detalji rezervacije', $html);
        $this->assertStringContainsString('Datum rezervacije', $html);
        $this->assertStringContainsString('Vrijeme dolaska', $html);
        $this->assertStringContainsString('Vrijeme odlaska', $html);
        $this->assertStringContainsString('Lokacija:', $html);
        $this->assertStringContainsString('Benovo', $html);
        $this->assertStringNotContainsString('Detalji limo usluge', $html);
        $this->assertStringNotContainsString('Tip usluge:', $html);
    }

    public function test_limo_invoice_template_shows_limo_details_not_reservation_slots(): void
    {
        $event = $this->makeLimoPickupEvent();
        $html = $this->renderLimoInvoiceHtml($event, false);

        $this->assertStringContainsString('Detalji limo usluge', $html);
        $this->assertStringContainsString('Tip usluge:', $html);
        $this->assertStringContainsString('Datum i vrijeme:', $html);
        $this->assertStringNotContainsString('Detalji rezervacije', $html);
        $this->assertStringNotContainsString('Vrijeme dolaska', $html);
        $this->assertStringNotContainsString('Vrijeme odlaska', $html);
    }

    public function test_render_binary_still_produces_non_empty_pdf_for_reservation(): void
    {
        $reservation = $this->makePaidReservationWithSlots();
        $binary = app(PaidInvoicePdfGenerator::class)->renderBinary($reservation, true);
        $this->assertGreaterThan(500, strlen($binary));
        $this->assertStringStartsWith('%PDF', $binary);
    }

    /**
     * Isti ključevi kao {@see PaidInvoicePdfGenerator::renderBinary()} (bez isLimoService).
     */
    private function renderReservationInvoiceHtml(Reservation $reservation, bool $isFiscal): string
    {
        $reservation->loadMissing(['vehicleType.translations', 'dropOffTimeSlot', 'pickUpTimeSlot']);

        $vehicleLine = 'Naknada';
        if ($reservation->vehicleType) {
            $vehicleLine = $reservation->vehicleType->getTranslatedDescription('cg')
                ?: $reservation->vehicleType->getTranslatedName('cg')
                ?: 'Naknada';
        }

        $unitPrice = (float) $reservation->invoice_amount;

        $fiscalDateTime = $reservation->created_at
            ? Carbon::parse($reservation->created_at)
            : now();

        $qrDataUri = $isFiscal
            ? KotorPdfAssets::fiscalVerificationQrDataUri($reservation->fiscal_qr)
            : null;

        $internalNumber = KotorPdfAssets::parseInternalNumberFromFiscalQr($reservation->fiscal_qr);

        return View::make('pdf.paid-invoice', [
            'reservation' => $reservation,
            'isFiscal' => $isFiscal,
            'logoDataUri' => KotorPdfAssets::logoDataUri(),
            'qrDataUri' => $qrDataUri,
            'countryDisplay' => KotorPdfAssets::countryDisplayCg((string) $reservation->country),
            'vehicleLine' => $vehicleLine,
            'unitPrice' => $unitPrice,
            'fiscalDateTime' => $fiscalDateTime,
            'internalNumber' => $internalNumber,
            'nonFiscalNote' => PaidInvoicePdfGenerator::nonFiscalNoteFor($reservation),
            'isDailyTicket' => $reservation->isDailyTicket(),
            'validityDateDisplay' => $reservation->reservation_date
                ? Carbon::parse($reservation->reservation_date)->format('d.m.Y')
                : '—',
        ])->render();
    }

    /**
     * Isti ključevi kao {@see PaidInvoicePdfGenerator::renderLimoBinary()}.
     */
    private function renderLimoInvoiceHtml(LimoPickupEvent $event, bool $isFiscal): string
    {
        $vm = LimoInvoiceAdapter::fromPickupEvent($event);
        $vm->fiscal_jir = $event->fiscal_jir;
        $vm->fiscal_ikof = $event->fiscal_ikof;
        $vm->fiscal_qr = $event->fiscal_qr;

        $vehicleLine = $event->service_name_snapshot ?: 'Naknada';
        $unitPrice = (float) $event->amount_snapshot;

        $fiscalDateTime = $event->fiscal_date
            ? Carbon::parse($event->fiscal_date)
            : ($event->occurred_at ? Carbon::parse($event->occurred_at) : now());

        $occurredAtDisplay = $event->occurred_at ? Carbon::parse($event->occurred_at) : null;

        $qrDataUri = $isFiscal
            ? KotorPdfAssets::fiscalVerificationQrDataUri($event->fiscal_qr)
            : null;

        $internalNumber = KotorPdfAssets::parseInternalNumberFromFiscalQr($event->fiscal_qr);

        return View::make('pdf.paid-invoice', [
            'reservation' => $vm,
            'isFiscal' => $isFiscal,
            'isLimoService' => true,
            'occurredAtDisplay' => $occurredAtDisplay,
            'logoDataUri' => KotorPdfAssets::logoDataUri(),
            'qrDataUri' => $qrDataUri,
            'countryDisplay' => KotorPdfAssets::countryDisplayCg((string) ($event->agency_country_snapshot ?? '')),
            'vehicleLine' => $vehicleLine,
            'unitPrice' => $unitPrice,
            'fiscalDateTime' => $fiscalDateTime,
            'internalNumber' => $internalNumber,
            'nonFiscalNote' => PaidInvoicePdfGenerator::NON_FISCAL_NOTE_TIME_SLOTS,
        ])->render();
    }

    private function makePaidReservationWithSlots(): Reservation
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '17:00 - 17:20']);

        $vt = VehicleType::query()->create(['price' => '99.99']);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $vt->id,
            'locale' => 'cg',
            'name' => 'Tip test',
            'description' => 'Opis tipa za PDF',
        ]);

        $date = Carbon::now()->addDays(5)->toDateString();

        return Reservation::query()->create([
            'merchant_transaction_id' => 'mt-pdf-reg-'.Str::random(8),
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Test Agency',
            'country' => 'ME',
            'license_plate' => 'KO999ZZ',
            'vehicle_type_id' => $vt->id,
            'email' => 'pdf-reg@test.local',
            'preferred_locale' => 'cg',
            'status' => 'paid',
            'invoice_amount' => '25.50',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'fiscal_jir' => null,
            'fiscal_ikof' => null,
            'fiscal_qr' => null,
        ]);
    }

    private function makeLimoPickupEvent(): LimoPickupEvent
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 11:30:00', 'Europe/Podgorica'));

        $admin = Admin::query()->create([
            'username' => 'pdf_reg_admin',
            'email' => 'pdf-reg-admin@test.local',
            'password' => bcrypt('secret'),
            'control_access' => false,
            'admin_access' => true,
            'limo_access' => true,
        ]);

        $user = User::factory()->create();

        return LimoPickupEvent::query()->create([
            'merchant_transaction_id' => (string) Str::uuid(),
            'agency_user_id' => $user->id,
            'agency_name_snapshot' => 'Sn',
            'agency_email_snapshot' => 'sn@test.local',
            'agency_country_snapshot' => 'ME',
            'source' => 'qr',
            'qr_token_hash' => null,
            'qr_valid_on' => Carbon::today('Europe/Podgorica'),
            'license_plate_snapshot' => 'KO111XX',
            'amount_snapshot' => '15.00',
            'service_name_snapshot' => LimoPickupService::SERVICE_NAME,
            'occurred_at' => now(),
            'recorded_by_limo_admin_id' => $admin->id,
            'status' => 'pending_fiscal',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
