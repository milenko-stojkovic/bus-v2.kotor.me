<?php

namespace Tests\Feature\Pdf;

use App\Models\Reservation;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\Pdf\KotorPdfAssets;
use App\Services\Pdf\PaidInvoicePdfGenerator;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use App\Jobs\SendInvoiceEmailJob;
use App\Models\User;
use Tests\TestCase;

final class DailyTicketPaidInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_invoice_daily_ticket_contains_daily_ticket_labels(): void
    {
        $html = $this->renderHtml($this->makeDailyTicketReservation(), false);

        $this->assertStringContainsString('Dnevna naknada', $html);
        $this->assertStringContainsString('Vrsta rezervacije:', $html);
        $this->assertStringContainsString('Datum važenja:', $html);
        $this->assertStringContainsString('Lokacija:', $html);
        $this->assertStringContainsString('teritorija opštine Kotor', $html);
        $this->assertStringNotContainsString('Autoboka i Puč', $html);
    }

    public function test_paid_invoice_daily_ticket_does_not_contain_slot_labels(): void
    {
        $html = $this->renderHtml($this->makeDailyTicketReservation(), false);

        $this->assertStringNotContainsString('Vrijeme dolaska', $html);
        $this->assertStringNotContainsString('Vrijeme odlaska', $html);
    }

    public function test_non_fiscal_fallback_note_uses_daily_ticket_wording(): void
    {
        $reservation = $this->makeDailyTicketReservation();

        $this->assertSame(
            PaidInvoicePdfGenerator::NON_FISCAL_NOTE_DAILY_TICKET,
            PaidInvoicePdfGenerator::nonFiscalNoteFor($reservation),
        );

        $html = $this->renderHtml($reservation, false);
        $this->assertStringContainsString('kupovini dnevne naknade', $html);
        $this->assertStringNotContainsString('kupovini termina', $html);
    }

    public function test_invoice_email_for_daily_ticket_mentions_daily_ticket_and_date(): void
    {
        $user = User::factory()->create(['lang' => 'en', 'email_verified_at' => now()]);
        $reservation = $this->makeDailyTicketReservation([
            'user_id' => $user->id,
            'email' => $user->email,
            'preferred_locale' => 'en',
        ]);

        $job = new SendInvoiceEmailJob($reservation->id, false);
        $ref = new \ReflectionMethod($job, 'buildConfirmationText');
        $ref->setAccessible(true);
        $body = $ref->invoke($job, $reservation->fresh(), 'en');

        $this->assertStringContainsString('daily fee', strtolower($body));
        $this->assertStringContainsString($reservation->reservation_date->format('Y-m-d'), $body);
        $this->assertStringNotContainsString('arrival', strtolower($body));
        $this->assertStringNotContainsString('departure', strtolower($body));
    }

    private function makeDailyTicketReservation(array $overrides = []): Reservation
    {
        $vt = VehicleType::query()->create(['price' => '40.00']);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $vt->id,
            'locale' => 'cg',
            'name' => 'Autobus',
            'description' => 'Opis',
        ]);

        $date = Carbon::parse('2026-08-15', 'Europe/Podgorica')->toDateString();

        return Reservation::query()->create(array_merge([
            'merchant_transaction_id' => 'mt-daily-pdf-'.Str::random(6),
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
            'reservation_date' => $date,
            'user_name' => 'Agency Daily',
            'country' => 'ME',
            'license_plate' => 'KO123DD',
            'vehicle_type_id' => $vt->id,
            'email' => 'daily-pdf@test.local',
            'preferred_locale' => 'cg',
            'status' => 'paid',
            'invoice_amount' => '40.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ], $overrides));
    }

    private function renderHtml(Reservation $reservation, bool $isFiscal): string
    {
        $reservation->loadMissing(['vehicleType.translations', 'dropOffTimeSlot', 'pickUpTimeSlot']);

        $vehicleLine = 'Naknada';
        if ($reservation->vehicleType) {
            $vehicleLine = $reservation->vehicleType->getTranslatedDescription('cg')
                ?: $reservation->vehicleType->getTranslatedName('cg')
                ?: 'Naknada';
        }

        $validityDate = $reservation->reservation_date
            ? Carbon::parse($reservation->reservation_date)->format('d.m.Y')
            : '—';

        return View::make('pdf.paid-invoice', [
            'reservation' => $reservation,
            'isFiscal' => $isFiscal,
            'isDailyTicket' => $reservation->isDailyTicket(),
            'validityDateDisplay' => $validityDate,
            'logoDataUri' => KotorPdfAssets::logoDataUri(),
            'qrDataUri' => null,
            'countryDisplay' => KotorPdfAssets::countryDisplayCg((string) $reservation->country),
            'vehicleLine' => $vehicleLine,
            'unitPrice' => (float) $reservation->invoice_amount,
            'fiscalDateTime' => Carbon::parse($reservation->created_at ?? now()),
            'internalNumber' => null,
            'nonFiscalNote' => PaidInvoicePdfGenerator::nonFiscalNoteFor($reservation),
        ])->render();
    }
}
