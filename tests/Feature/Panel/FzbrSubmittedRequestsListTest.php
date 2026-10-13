<?php

namespace Tests\Feature\Panel;

use App\Models\FreeReservationRequest;
use App\Models\FreeReservationRequestSegment;
use App\Models\FreeReservationRequestVehicle;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FzbrSubmittedRequestsListTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_agency_sees_its_own_submitted_free_reservation_requests(): void
    {
        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $req = $this->seedRequest($user, FreeReservationRequest::STATUS_SUBMITTED, 'KO111AA');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();

        $this->assertStringContainsString('Moji poslati zahtjevi', $html);
        $this->assertStringContainsString('KO111AA', $html);
        $this->assertStringContainsString($req->reservation_date->format('Y-m-d'), $html);
    }

    public function test_agency_does_not_see_requests_from_another_agency(): void
    {
        $owner = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $other = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);

        $this->seedRequest($other, FreeReservationRequest::STATUS_SUBMITTED, 'OTHER_PLATE');

        $this->actingAs($owner);
        app()->setLocale('cg');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();

        $this->assertStringContainsString('Nemate poslatih zahtjeva za besplatne rezervacije.', $html);
        $this->assertStringNotContainsString('OTHER_PLATE', $html);
    }

    public function test_pending_request_shows_ceka_se_obrada(): void
    {
        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $this->seedRequest($user, FreeReservationRequest::STATUS_SUBMITTED, 'KO-PEND');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('Čeka se obrada', $html);
    }

    public function test_updated_request_shows_ceka_se_obrada(): void
    {
        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $this->seedRequest($user, FreeReservationRequest::STATUS_UPDATED, 'KO-UPD');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('Čeka se obrada', $html);
    }

    public function test_rejected_request_shows_odbijeno(): void
    {
        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $this->seedRequest($user, FreeReservationRequest::STATUS_REJECTED, 'KO-REJ');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('Odbijeno', $html);
        $this->assertStringContainsString('KO-REJ', $html);
    }

    public function test_agency_sees_its_own_rejection_justification_in_cg(): void
    {
        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);

        $req = $this->seedRequest($user, FreeReservationRequest::STATUS_REJECTED, 'KO-REJ-CG');
        $req->update([
            'rejection_reason' => "Dokumentacija nije dovoljna.\nDostavite ovlašćenje.",
        ]);

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('Odbijeno', $html);
        $this->assertStringContainsString('Razlog odbijanja', $html);
        $this->assertStringContainsString('whitespace-pre-wrap', $html);
        $this->assertStringContainsString("Dokumentacija nije dovoljna.\nDostavite ovlašćenje.", $html);
        $this->assertStringContainsString('KO-REJ-CG', $html);
        $this->assertStringNotContainsString('reject-admin@example.com', $html);
    }

    public function test_agency_sees_rejection_justification_label_in_english(): void
    {
        $user = User::factory()->create(['lang' => 'en', 'country' => 'ME']);
        $this->actingAs($user);

        $req = $this->seedRequest($user, FreeReservationRequest::STATUS_REJECTED, 'KO-REJ-EN');
        $req->update([
            'rejection_reason' => "The documents are not sufficient.\nPlease send the authorization.",
        ]);

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('Rejected', $html);
        $this->assertStringContainsString('Rejection reason', $html);
        $this->assertStringContainsString("The documents are not sufficient.\nPlease send the authorization.", $html);
        $this->assertStringNotContainsString('Razlog odbijanja', $html);
    }

    public function test_agency_cannot_see_another_agencys_rejection_justification(): void
    {
        $owner = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $other = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);

        $foreign = $this->seedRequest($other, FreeReservationRequest::STATUS_REJECTED, 'OTHER_REJ_PLATE');
        $foreign->update([
            'rejection_reason' => 'SECRET_REJECTION_REASON_OTHER_AGENCY',
        ]);

        $own = $this->seedRequest($owner, FreeReservationRequest::STATUS_REJECTED, 'OWN_REJ_PLATE');
        $own->update([
            'rejection_reason' => 'OWN_AGENCY_REJECTION_REASON',
        ]);

        $this->actingAs($owner);

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('OWN_AGENCY_REJECTION_REASON', $html);
        $this->assertStringContainsString('OWN_REJ_PLATE', $html);
        $this->assertStringNotContainsString('SECRET_REJECTION_REASON_OTHER_AGENCY', $html);
        $this->assertStringNotContainsString('OTHER_REJ_PLATE', $html);
    }

    public function test_approved_fulfilled_request_shows_odobreno(): void
    {
        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $this->seedRequest($user, FreeReservationRequest::STATUS_FULFILLED, 'KO-APP');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('Odobreno', $html);
    }

    public function test_fulfilled_request_with_one_future_linked_free_reservation_shows_that_reservation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Europe/Podgorica'));

        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $req = $this->seedRequest($user, FreeReservationRequest::STATUS_FULFILLED, 'KO-REQ-SNAP', '2026-07-10');
        $this->seedLinkedReservation($req, '2026-07-15', '18:00 - 18:20', 'KO-FUTURE-LINK', '14:00 - 14:20');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('2026-07-15', $html);
        $this->assertStringContainsString('14:00 - 14:20', $html);
        $this->assertStringContainsString('18:00 - 18:20', $html);
        $this->assertStringContainsString('KO-FUTURE-LINK', $html);
        $this->assertStringNotContainsString('KO-REQ-SNAP', $html);
        $this->assertStringContainsString('Odobreno', $html);
    }

    public function test_fulfilled_request_with_one_past_linked_free_reservation_is_hidden(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 19:00:00', 'Europe/Podgorica'));

        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $req = $this->seedRequest($user, FreeReservationRequest::STATUS_FULFILLED, 'KO-PAST', '2026-07-10');
        $this->seedLinkedReservation($req, '2026-07-10', '18:00 - 18:20', 'KO-PAST-LINK');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('Nemate poslatih zahtjeva za besplatne rezervacije.', $html);
        $this->assertStringNotContainsString('KO-PAST', $html);
        $this->assertStringNotContainsString('KO-PAST-LINK', $html);
    }

    public function test_fulfilled_request_with_multiple_linked_reservations_shows_only_upcoming_ones(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 19:00:00', 'Europe/Podgorica'));

        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $req = $this->seedRequest($user, FreeReservationRequest::STATUS_FULFILLED, 'KO-MULTI', '2026-07-10');
        $this->seedLinkedReservation($req, '2026-07-10', '18:00 - 18:20', 'KO-MULTI-PAST', '08:00 - 08:20');
        $this->seedLinkedReservation($req, '2026-07-15', '18:00 - 18:20', 'KO-MULTI-FUTURE', '10:00 - 10:20');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('2026-07-15', $html);
        $this->assertStringContainsString('KO-MULTI-FUTURE', $html);
        $this->assertStringContainsString('10:00 - 10:20', $html);
        $this->assertStringContainsString('Odobreno', $html);
        $this->assertStringNotContainsString('KO-MULTI-PAST', $html);
        $this->assertStringNotContainsString('08:00 - 08:20', $html);
    }

    public function test_request_linked_to_future_free_reservation_remains_visible(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Europe/Podgorica'));

        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $req = $this->seedRequest($user, FreeReservationRequest::STATUS_FULFILLED, 'KO-REQ-SNAP', '2026-07-15');
        $this->seedLinkedReservation($req, '2026-07-15', '18:00 - 18:20', 'KO-FUTURE');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('KO-FUTURE', $html);
        $this->assertStringContainsString('Odobreno', $html);
        $this->assertStringNotContainsString('KO-REQ-SNAP', $html);
    }

    public function test_request_linked_to_realized_free_reservation_is_hidden(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 19:00:00', 'Europe/Podgorica'));

        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $req = $this->seedRequest($user, FreeReservationRequest::STATUS_FULFILLED, 'KO-PAST', '2026-07-10');
        $this->seedLinkedReservation($req, '2026-07-10', '18:00 - 18:20');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('Nemate poslatih zahtjeva za besplatne rezervacije.', $html);
        $this->assertStringNotContainsString('KO-PAST', $html);
    }

    public function test_request_with_multiple_linked_reservations_hidden_only_after_all_realized(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 19:00:00', 'Europe/Podgorica'));

        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $req = $this->seedRequest($user, FreeReservationRequest::STATUS_FULFILLED, 'KO-MULTI', '2026-07-10');
        $this->seedLinkedReservation($req, '2026-07-10', '18:00 - 18:20', 'KO-MULTI-A');
        $this->seedLinkedReservation($req, '2026-07-15', '18:00 - 18:20', 'KO-MULTI-B');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('KO-MULTI', $html);
        $this->assertStringContainsString('Odobreno', $html);
    }

    public function test_request_with_multiple_linked_reservations_hidden_when_all_realized(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20 19:00:00', 'Europe/Podgorica'));

        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $req = $this->seedRequest($user, FreeReservationRequest::STATUS_FULFILLED, 'KO-ALL-DONE', '2026-07-10');
        $this->seedLinkedReservation($req, '2026-07-10', '18:00 - 18:20', 'KO-ALL-DONE-A');
        $this->seedLinkedReservation($req, '2026-07-15', '18:00 - 18:20', 'KO-ALL-DONE-B');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('Nemate poslatih zahtjeva za besplatne rezervacije.', $html);
        $this->assertStringNotContainsString('KO-ALL-DONE', $html);
    }

    public function test_rejected_request_with_past_reservation_date_is_hidden(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-14 18:00:00', 'Europe/Podgorica'));

        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $this->seedRequest($user, FreeReservationRequest::STATUS_REJECTED, 'KO-REJ-PAST', '2026-04-28');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringNotContainsString('KO-REJ-PAST', $html);
        $this->assertStringNotContainsString('Odbijeno', $html);
    }

    public function test_rejected_request_with_future_reservation_date_stays_visible(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-14 18:00:00', 'Europe/Podgorica'));

        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $this->seedRequest($user, FreeReservationRequest::STATUS_REJECTED, 'KO-REJ-FUT', '2026-06-20');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('KO-REJ-FUT', $html);
        $this->assertStringContainsString('Odbijeno', $html);
    }

    public function test_submitted_and_rejected_requests_still_show_as_request_records(): void
    {
        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $this->seedRequest($user, FreeReservationRequest::STATUS_SUBMITTED, 'KO-SUB-REC');
        $this->seedRequest($user, FreeReservationRequest::STATUS_REJECTED, 'KO-REJ-REC');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('KO-SUB-REC', $html);
        $this->assertStringContainsString('KO-REJ-REC', $html);
        $this->assertStringContainsString('Čeka se obrada', $html);
        $this->assertStringContainsString('Odbijeno', $html);
    }

    public function test_legacy_fulfilled_request_without_linked_reservations_stays_visible_when_still_upcoming(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Europe/Podgorica'));

        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $this->seedRequest($user, FreeReservationRequest::STATUS_FULFILLED, 'KO-LEGACY', '2026-07-15');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('KO-LEGACY', $html);
        $this->assertStringContainsString('Odobreno', $html);
    }

    public function test_legacy_fulfilled_request_without_linked_reservations_is_hidden_when_past(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 19:00:00', 'Europe/Podgorica'));

        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $this->seedRequest($user, FreeReservationRequest::STATUS_FULFILLED, 'KO-LEGACY-PAST', '2026-07-10');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('Nemate poslatih zahtjeva za besplatne rezervacije.', $html);
        $this->assertStringNotContainsString('KO-LEGACY-PAST', $html);
    }

    public function test_fulfilled_request_discovers_orphan_free_reservations_without_fk(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 19:00:00', 'Europe/Podgorica'));

        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME', 'email' => 'agency@example.com']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $req = $this->seedRequest($user, FreeReservationRequest::STATUS_FULFILLED, 'KO-ORPHAN-PAST', '2026-07-10');
        $this->seedLinkedReservationForRequestSegment($req, 'KO-ORPHAN-PAST', linkFk: false);

        $futureReq = $this->seedRequest($user, FreeReservationRequest::STATUS_FULFILLED, 'KO-ORPHAN-FUT', '2026-07-15');
        $this->seedLinkedReservationForRequestSegment($futureReq, 'KO-ORPHAN-FUT', linkFk: false);

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('KO-ORPHAN-FUT', $html);
        $this->assertStringNotContainsString('KO-ORPHAN-PAST', $html);

        $this->assertSame(
            $futureReq->id,
            (int) Reservation::query()->where('license_plate', 'KO-ORPHAN-FUT')->value('free_reservation_request_id')
        );
    }

    public function test_empty_state_when_no_visible_requests_exist(): void
    {
        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);
        app()->setLocale('cg');

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('Nemate poslatih zahtjeva za besplatne rezervacije.', $html);
    }

    private function seedRequest(
        User $user,
        string $status,
        string $plate,
        ?string $reservationDate = null,
    ): FreeReservationRequest {
        $date = $reservationDate ?? Carbon::now()->addDays(5)->toDateString();
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $vt = VehicleType::query()->create(['price' => 10]);

        $req = FreeReservationRequest::query()->create([
            'user_id' => $user->id,
            'locale' => 'cg',
            'institution_name' => $user->name,
            'institution_email' => $user->email,
            'institution_phone' => null,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'country' => 'ME',
            'status' => $status,
        ]);

        $seg = FreeReservationRequestSegment::query()->create([
            'request_id' => $req->id,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'position' => 1,
        ]);

        FreeReservationRequestVehicle::query()->create([
            'request_id' => $req->id,
            'segment_id' => $seg->id,
            'license_plate' => $plate,
            'vehicle_type_id' => $vt->id,
            'vehicle_type_label' => 'Autobus',
        ]);

        return $req;
    }

    private function seedLinkedReservation(
        FreeReservationRequest $req,
        string $date,
        string $pickSlotLabel,
        ?string $plate = null,
        string $dropSlotLabel = '10:00 - 10:20',
        bool $linkFk = true,
    ): Reservation {
        $drop = ListOfTimeSlot::query()->firstOrCreate(['time_slot' => $dropSlotLabel]);
        $pick = ListOfTimeSlot::query()->firstOrCreate(['time_slot' => $pickSlotLabel]);
        $vt = VehicleType::query()->first() ?? VehicleType::query()->create(['price' => 10]);

        return Reservation::query()->create([
            'free_reservation_request_id' => $linkFk ? $req->id : null,
            'merchant_transaction_id' => 'mt-fzbr-'.Str::random(8),
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => $req->institution_name,
            'country' => $req->country,
            'license_plate' => $plate ?? 'KO-LINK',
            'vehicle_type_id' => $vt->id,
            'email' => $req->institution_email,
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => true,
        ]);
    }

    private function seedLinkedReservationForRequestSegment(
        FreeReservationRequest $req,
        string $plate,
        bool $linkFk = true,
    ): Reservation {
        $seg = FreeReservationRequestSegment::query()->where('request_id', $req->id)->firstOrFail();
        $vt = VehicleType::query()->first() ?? VehicleType::query()->create(['price' => 10]);

        return Reservation::query()->create([
            'free_reservation_request_id' => $linkFk ? $req->id : null,
            'merchant_transaction_id' => 'mt-fzbr-'.Str::random(8),
            'drop_off_time_slot_id' => $seg->drop_off_time_slot_id,
            'pick_up_time_slot_id' => $seg->pick_up_time_slot_id,
            'reservation_date' => $seg->reservation_date?->toDateString() ?? $req->reservation_date?->toDateString(),
            'user_name' => $req->institution_name,
            'country' => $req->country,
            'license_plate' => $plate,
            'vehicle_type_id' => $vt->id,
            'email' => $req->institution_email,
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => true,
        ]);
    }
}
