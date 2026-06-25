<?php

namespace Tests\Feature\Email;

use App\Jobs\SendFreeReservationConfirmationJob;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\VehicleType;
use App\Support\UiText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

final class FreeReservationConfirmationEmailTest extends TestCase
{
    use RefreshDatabase;

    private function makeFreeReservation(array $overrides = []): Reservation
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '00:00 - 07:00']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '00:00 - 07:00']);
        $vt = VehicleType::query()->create(['price' => 0]);

        return Reservation::query()->create(array_merge([
            'merchant_transaction_id' => 'mt-free-'.uniqid('', true),
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => now()->addDays(2)->toDateString(),
            'user_name' => 'Guest Free User',
            'country' => 'ME',
            'license_plate' => 'KO-FREE-1',
            'vehicle_type_id' => $vt->id,
            'email' => 'free@example.com',
            'preferred_locale' => 'en',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
        ], $overrides));
    }

    public function test_free_reservation_email_uses_unified_english_copy(): void
    {
        $reservation = $this->makeFreeReservation([
            'user_name' => 'John Smith',
            'preferred_locale' => 'en',
        ]);

        $job = new SendFreeReservationConfirmationJob($reservation->id);
        $subjectMethod = new ReflectionMethod($job, 'resolveFreeReservationEmailSubject');
        $subjectMethod->setAccessible(true);
        $bodyMethod = new ReflectionMethod($job, 'buildConfirmationText');
        $bodyMethod->setAccessible(true);

        $subject = $subjectMethod->invoke($job, 'en');
        $body = $bodyMethod->invoke($job, $reservation->fresh(), 'en');

        $this->assertSame('Free reservation confirmation', $subject);
        $this->assertStringContainsString('Dear John Smith,', $body);
        $this->assertStringContainsString('successfully created', $body);
        $this->assertStringContainsString('free parking reservation confirmation', $body);
        $this->assertStringContainsString('Municipality of Kotor', $body);
        $this->assertStringNotContainsString('fiscal', strtolower($body));
        $this->assertStringNotContainsString('#', $body);
    }

    public function test_free_reservation_email_uses_unified_cg_copy(): void
    {
        $reservation = $this->makeFreeReservation([
            'user_name' => 'Marko Marković',
            'preferred_locale' => 'cg',
        ]);

        $job = new SendFreeReservationConfirmationJob($reservation->id);
        $subjectMethod = new ReflectionMethod($job, 'resolveFreeReservationEmailSubject');
        $subjectMethod->setAccessible(true);
        $bodyMethod = new ReflectionMethod($job, 'buildConfirmationText');
        $bodyMethod->setAccessible(true);

        $subject = $subjectMethod->invoke($job, 'cg');
        $body = $bodyMethod->invoke($job, $reservation->fresh(), 'cg');

        $this->assertSame('Potvrda besplatne rezervacije', $subject);
        $this->assertStringContainsString('Poštovani Marko Marković,', $body);
        $this->assertStringContainsString('uspješno kreirana', $body);
        $this->assertStringContainsString('potvrda besplatne rezervacije parkinga', $body);
        $this->assertStringContainsString('Opština Kotor', $body);
    }

    public function test_free_reservation_email_tolerates_stale_db_template(): void
    {
        DB::table('ui_translations')->updateOrInsert(
            ['group' => 'emails', 'key' => 'free_reservation_body', 'locale' => 'en'],
            [
                'text' => "Hello,\n\nYour free parking reservation #%1\$d is confirmed for %2\$s.\nNo payment was required.",
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        Cache::forget('ui_translations:group=emails:locale=en');
        Cache::forget('ui_translations:any:group=emails:key=free_reservation_body');

        $reservation = $this->makeFreeReservation([
            'user_name' => 'Stale Template Test',
            'preferred_locale' => 'en',
        ]);

        $job = new SendFreeReservationConfirmationJob($reservation->id);
        $bodyMethod = new ReflectionMethod($job, 'buildConfirmationText');
        $bodyMethod->setAccessible(true);
        $body = $bodyMethod->invoke($job, $reservation->fresh(), 'en');

        $this->assertStringContainsString('Dear Stale Template Test,', $body);
        $this->assertStringContainsString('successfully created', $body);
        $this->assertStringNotContainsString('confirmed for', $body);
    }

    public function test_ui_text_keys_match_expected_copy(): void
    {
        $this->assertSame(
            'Free reservation confirmation',
            UiText::t('emails', 'free_reservation_subject', '', 'en')
        );
        $this->assertSame(
            'Potvrda besplatne rezervacije',
            UiText::t('emails', 'free_reservation_subject', '', 'cg')
        );
    }

    public function test_fulfilled_request_mail_uses_fulfilled_copy_not_single_reservation_copy(): void
    {
        $cgBody = UiText::t(
            'emails',
            'free_request_fulfilled_body',
            "Zdravo,\n\nVaš zahtjev je obrađen i potvrde besplatnih rezervacija su u prilogu.\nBroj vozila: %1\$d\nDatum: %2\$s\n\nHvala vam.",
            'cg'
        );
        $enBody = UiText::t(
            'emails',
            'free_request_fulfilled_body',
            "Hello,\n\nYour request has been processed and free reservation confirmations are attached.\nVehicles: %1\$d\nDate: %2\$s\n\nThank you.",
            'en'
        );

        $this->assertStringContainsString('obrađen', $cgBody);
        $this->assertStringContainsString('processed', $enBody);
    }
}
