<?php

namespace Tests\Feature\AdminPanel;

use App\Jobs\SendFreeReservationConfirmationJob;
use App\Mail\FreeReservationRequestFulfilledMail;
use App\Models\AdminAlert;
use App\Models\Admin;
use App\Models\FreeReservationRequest;
use App\Models\FreeReservationRequestAttachment;
use App\Models\FreeReservationRequestSegment;
use App\Models\FreeReservationRequestVehicle;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPanelFreeReservationTest extends TestCase
{
    use RefreshDatabase;

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'freeadmin',
            'email' => 'free-admin@example.com',
            'password' => bcrypt('secret-password-fr'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    /**
     * @return array{0: string, 1: ListOfTimeSlot, 2: ListOfTimeSlot, 3: VehicleType}
     */
    private function seedSlotsAndVehicle(string $date): array
    {
        $s1 = ListOfTimeSlot::query()->create(['time_slot' => '08:00 - 08:20']);
        $s2 = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);
        $vt = VehicleType::query()->create(['price' => 10]);
        foreach (['en', 'cg'] as $locale) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $vt->id,
                'locale' => $locale,
                'name' => 'Autobus',
                'description' => null,
            ]);
        }
        foreach ([$s1, $s2] as $slot) {
            DailyParkingData::query()->create([
                'date' => $date,
                'time_slot_id' => $slot->id,
                'capacity' => 5,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }

        return [$date, $s1, $s2, $vt];
    }

    public function test_admin_can_create_free_reservation_without_temp_data(): void
    {
        Queue::fake();

        $admin = $this->seedAdmin();
        $date = Carbon::now()->addDay()->toDateString();
        [$date, $s1, $s2, $vt] = $this->seedSlotsAndVehicle($date);

        $this->actingAs($admin, 'panel_admin');

        $response = $this->post(route('panel_admin.free-reservations.store', [], false), [
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $s1->id,
            'pick_up_time_slot_id' => $s2->id,
            'vehicle_type_id' => $vt->id,
            'name' => 'Škola Primjer',
            'country' => 'ME',
            'license_plate' => 'KO123AB',
            'email' => 'skola@example.com',
        ]);

        $response->assertRedirect(route('panel_admin.free-reservations', [], false));
        $response->assertSessionHas('status');

        $r = Reservation::query()->where('email', 'skola@example.com')->first();
        $this->assertNotNull($r);
        $this->assertSame('free', $r->status);
        $this->assertTrue($r->created_by_admin);
        $this->assertNull($r->user_id);
        $this->assertSame('cg', $r->preferred_locale);
        $this->assertSame('0.00', (string) $r->invoice_amount);

        $this->assertSame(1, (int) DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $s1->id)->value('reserved'));
        $this->assertSame(1, (int) DailyParkingData::query()->whereDate('date', $date)->where('time_slot_id', $s2->id)->value('reserved'));

        Queue::assertPushed(SendFreeReservationConfirmationJob::class, function (SendFreeReservationConfirmationJob $job) use ($r): bool {
            return $job->reservationId === $r->id;
        });
    }

    public function test_slot_conflict_preserves_guest_fields_and_clears_slots_in_redirect(): void
    {
        $admin = $this->seedAdmin();
        $date = Carbon::now()->addDay()->toDateString();
        [$date, $s1, $s2, $vt] = $this->seedSlotsAndVehicle($date);

        DailyParkingData::query()
            ->whereDate('date', $date)
            ->where('time_slot_id', $s1->id)
            ->update(['reserved' => 5]);

        $this->actingAs($admin, 'panel_admin');

        $response = $this->post(route('panel_admin.free-reservations.store', [], false), [
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $s1->id,
            'pick_up_time_slot_id' => $s2->id,
            'vehicle_type_id' => $vt->id,
            'name' => 'Zadržano Ime',
            'country' => 'ME',
            'license_plate' => 'AB999CD',
            'email' => 'zadrzano@example.com',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $url = (string) $response->headers->get('Location');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('Zadržano Ime', $query['name'] ?? '');
        $this->assertSame((string) $vt->id, $query['vehicle_type_id'] ?? '');
        $this->assertArrayNotHasKey('reservation_date', $query);
        $this->assertArrayNotHasKey('drop_off_time_slot_id', $query);

        $this->assertSame(0, Reservation::query()->count());
    }

    public function test_free_reservations_page_renders_for_panel_admin(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.free-reservations', [], false))
            ->assertOk()
            ->assertSee('Napravi besplatnu rezervaciju', false);
    }

    public function test_free_reservation_requests_are_listed_and_filtered_on_free_reservations_page(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slotA = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slotB = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $vt = VehicleType::query()->create(['price' => 10]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $vt->id,
            'locale' => 'cg',
            'name' => 'Autobus',
            'description' => 'veliki',
        ]);

        $base = [
            'locale' => 'cg',
            'institution_name' => 'OŠ Kotor',
            'institution_email' => 'school@example.com',
            'institution_phone' => '+38267111222',
            'reservation_date' => Carbon::now()->addDays(3)->toDateString(),
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'country' => 'ME',
        ];

        $submitted = FreeReservationRequest::query()->create([
            ...$base,
            'status' => FreeReservationRequest::STATUS_SUBMITTED,
        ]);
        $submittedSeg = FreeReservationRequestSegment::query()->create([
            'request_id' => $submitted->id,
            'reservation_date' => $submitted->reservation_date->toDateString(),
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'position' => 1,
        ]);
        FreeReservationRequestVehicle::query()->create([
            'request_id' => $submitted->id,
            'segment_id' => $submittedSeg->id,
            'license_plate' => 'KO123AB',
            'vehicle_type_id' => $vt->id,
        ]);

        $updated = FreeReservationRequest::query()->create([
            ...$base,
            'institution_name' => 'Crveni krst',
            'institution_email' => 'redcross@example.com',
            'status' => FreeReservationRequest::STATUS_UPDATED,
        ]);
        $updatedSeg = FreeReservationRequestSegment::query()->create([
            'request_id' => $updated->id,
            'reservation_date' => $updated->reservation_date->toDateString(),
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'position' => 1,
        ]);
        FreeReservationRequestVehicle::query()->create([
            'request_id' => $updated->id,
            'segment_id' => $updatedSeg->id,
            'license_plate' => 'KO999',
            'vehicle_type_id' => $vt->id,
        ]);

        $fulfilled = FreeReservationRequest::query()->create([
            ...$base,
            'institution_name' => 'Ne prikazuj',
            'institution_email' => 'hidden@example.com',
            'status' => FreeReservationRequest::STATUS_FULFILLED,
        ]);
        $rejected = FreeReservationRequest::query()->create([
            ...$base,
            'institution_name' => 'Ne prikazuj 2',
            'institution_email' => 'hidden2@example.com',
            'status' => FreeReservationRequest::STATUS_REJECTED,
        ]);

        // Terminal requests appear in the FZBR review table when updated_at falls in the filter window;
        // keep them outside the default "today" review range so this test still targets only the active queue.
        \Illuminate\Support\Facades\DB::table('free_reservation_requests')->whereIn('id', [$fulfilled->id, $rejected->id])->update([
            'updated_at' => Carbon::now()->subDays(120),
        ]);

        $html = $this->get(route('panel_admin.free-reservations', [], false))->assertOk()->getContent();

        // Shows only active statuses
        $this->assertStringContainsString('OŠ Kotor', $html);
        $this->assertStringContainsString('Crveni krst', $html);
        $this->assertStringNotContainsString('Ne prikazuj', $html);
        $this->assertStringNotContainsString('hidden@example.com', $html);

        // Child vehicles shown
        $this->assertStringContainsString('KO123AB', $html);
        $this->assertStringContainsString('KO999', $html);
        $this->assertStringContainsString('Autobus', $html);

        // Phone is NOT shown in operativni prikaz (agency FZBR model)
        $this->assertStringNotContainsString('+38267111222', $html);
    }

    public function test_request_card_disables_fulfill_when_capacity_insufficient(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $d = Carbon::now()->addDays(3)->toDateString();
        $slotA = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slotB = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $vt = VehicleType::query()->create(['price' => 10]);
        VehicleTypeTranslation::query()->create(['vehicle_type_id' => $vt->id, 'locale' => 'cg', 'name' => 'Autobus', 'description' => null]);

        // Only 1 capacity, but request has 2 vehicles
        foreach ([$slotA, $slotB] as $s) {
            DailyParkingData::query()->create([
                'date' => $d,
                'time_slot_id' => $s->id,
                'capacity' => 1,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }

        $req = FreeReservationRequest::query()->create([
            'locale' => 'cg',
            'institution_name' => 'OŠ Kotor',
            'institution_email' => 'school@example.com',
            'institution_phone' => '+38267111222',
            'reservation_date' => $d,
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'country' => 'ME',
            'status' => FreeReservationRequest::STATUS_SUBMITTED,
        ]);
        $seg = FreeReservationRequestSegment::query()->create([
            'request_id' => $req->id,
            'reservation_date' => $d,
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'position' => 1,
        ]);
        FreeReservationRequestVehicle::query()->create(['request_id' => $req->id, 'segment_id' => $seg->id, 'license_plate' => 'KO111', 'vehicle_type_id' => $vt->id]);
        FreeReservationRequestVehicle::query()->create(['request_id' => $req->id, 'segment_id' => $seg->id, 'license_plate' => 'KO222', 'vehicle_type_id' => $vt->id]);

        $html = $this->get(route('panel_admin.free-reservations', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('nema dovoljno slobodnih kapaciteta', $html);
        $this->assertStringContainsString('Napravi besplatnu/e rezervaciju/e', $html);
        $this->assertStringContainsString('disabled', $html);
    }

    public function test_update_request_changes_date_slots_and_sets_status_updated(): void
    {
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $vt = VehicleType::query()->create(['price' => 10]);
        VehicleTypeTranslation::query()->create(['vehicle_type_id' => $vt->id, 'locale' => 'cg', 'name' => 'Autobus', 'description' => null]);

        $slot1 = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slot2 = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $slot3 = ListOfTimeSlot::query()->create(['time_slot' => '12:00 - 12:20']);
        $slot4 = ListOfTimeSlot::query()->create(['time_slot' => '13:00 - 13:20']);

        $d1 = Carbon::now()->addDays(3)->toDateString();
        $d2 = Carbon::now()->addDays(4)->toDateString();
        foreach ([[$d1, $slot1], [$d1, $slot2], [$d2, $slot3], [$d2, $slot4]] as [$d, $s]) {
            DailyParkingData::query()->create([
                'date' => $d,
                'time_slot_id' => $s->id,
                'capacity' => 5,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }

        $req = FreeReservationRequest::query()->create([
            'locale' => 'cg',
            'institution_name' => 'OŠ Kotor',
            'institution_email' => 'school@example.com',
            'institution_phone' => '+38267111222',
            'reservation_date' => $d1,
            'drop_off_time_slot_id' => $slot1->id,
            'pick_up_time_slot_id' => $slot2->id,
            'country' => 'ME',
            'status' => FreeReservationRequest::STATUS_SUBMITTED,
        ]);
        $seg = FreeReservationRequestSegment::query()->create([
            'request_id' => $req->id,
            'reservation_date' => $d1,
            'drop_off_time_slot_id' => $slot1->id,
            'pick_up_time_slot_id' => $slot2->id,
            'position' => 1,
        ]);
        FreeReservationRequestVehicle::query()->create(['request_id' => $req->id, 'segment_id' => $seg->id, 'license_plate' => 'KO111', 'vehicle_type_id' => $vt->id]);

        $this->put(route('panel_admin.free-reservation-requests.update', ['freeReservationRequest' => $req->id], false), [
            'reservation_date' => $d2,
            'segments' => [
                [
                    'id' => $seg->id,
                    'drop_off_time_slot_id' => $slot3->id,
                    'pick_up_time_slot_id' => $slot4->id,
                ],
            ],
        ])->assertRedirect();

        $req->refresh();
        $this->assertSame($d2, $req->reservation_date->toDateString());
        $this->assertSame($slot3->id, (int) $req->drop_off_time_slot_id);
        $this->assertSame($slot4->id, (int) $req->pick_up_time_slot_id);
        $this->assertSame(FreeReservationRequest::STATUS_UPDATED, $req->status);
    }

    public function test_reject_request_marks_request_rejected_and_removes_warning_pointer_but_keeps_request(): void
    {
        Storage::fake('local');
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slotA = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slotB = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $req = FreeReservationRequest::query()->create([
            'locale' => 'cg',
            'institution_name' => 'OŠ Kotor',
            'institution_email' => 'school@example.com',
            'institution_phone' => '+38267111222',
            'reservation_date' => Carbon::now()->addDays(3)->toDateString(),
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'country' => 'ME',
            'status' => FreeReservationRequest::STATUS_SUBMITTED,
        ]);
        AdminAlert::query()->create([
            'type' => 'free_reservation_request',
            'status' => AdminAlert::STATUS_UNREAD,
            'title' => 't',
            'message' => 'm',
            'payload_json' => ['free_reservation_request_id' => $req->id],
        ]);

        Storage::disk('local')->put('free-reservation-requests/'.$req->id.'/doc.pdf', 'PDF');
        FreeReservationRequestAttachment::query()->create([
            'request_id' => $req->id,
            'original_name' => 'doc.pdf',
            'stored_path' => 'free-reservation-requests/'.$req->id.'/doc.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 3,
        ]);

        $this->delete(route('panel_admin.free-reservation-requests.reject', ['freeReservationRequest' => $req->id], false), [
            'confirm' => 1,
        ])->assertRedirect();

        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_REJECTED, $req->status);
        $this->assertSame(1, FreeReservationRequestAttachment::query()->where('request_id', $req->id)->count());
        Storage::disk('local')->assertExists('free-reservation-requests/'.$req->id.'/doc.pdf');
        $alert = AdminAlert::query()->first();
        $this->assertNotNull($alert);
        $this->assertNotNull($alert->removed_at);
    }

    public function test_fulfill_creates_one_free_reservation_per_vehicle_and_marks_request_fulfilled_without_deleting_it(): void
    {
        Storage::fake('local');
        Mail::fake();

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $d = Carbon::now()->addDays(3)->toDateString();
        $slotA = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slotB = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        foreach ([$slotA, $slotB] as $s) {
            DailyParkingData::query()->create([
                'date' => $d,
                'time_slot_id' => $s->id,
                'capacity' => 10,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }

        $vt = VehicleType::query()->create(['price' => 10]);
        VehicleTypeTranslation::query()->create(['vehicle_type_id' => $vt->id, 'locale' => 'cg', 'name' => 'Autobus', 'description' => null]);

        $req = FreeReservationRequest::query()->create([
            'locale' => 'en',
            'institution_name' => 'OŠ Kotor',
            'institution_email' => 'school@example.com',
            'institution_phone' => '+38267111222',
            'reservation_date' => $d,
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'country' => 'ME',
            'status' => FreeReservationRequest::STATUS_SUBMITTED,
        ]);
        $seg = FreeReservationRequestSegment::query()->create([
            'request_id' => $req->id,
            'reservation_date' => $d,
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'position' => 1,
        ]);
        FreeReservationRequestVehicle::query()->create(['request_id' => $req->id, 'segment_id' => $seg->id, 'license_plate' => 'KO111', 'vehicle_type_id' => $vt->id]);
        FreeReservationRequestVehicle::query()->create(['request_id' => $req->id, 'segment_id' => $seg->id, 'license_plate' => 'KO222', 'vehicle_type_id' => $vt->id]);

        AdminAlert::query()->create([
            'type' => 'free_reservation_request',
            'status' => AdminAlert::STATUS_UNREAD,
            'title' => 't',
            'message' => 'm',
            'payload_json' => ['free_reservation_request_id' => $req->id],
        ]);

        // One attachment must survive fulfill (retention).
        Storage::disk('local')->put('free-reservation-requests/'.$req->id.'/doc.pdf', 'PDF');
        FreeReservationRequestAttachment::query()->create([
            'request_id' => $req->id,
            'original_name' => 'doc.pdf',
            'stored_path' => 'free-reservation-requests/'.$req->id.'/doc.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 3,
        ]);

        // Mock PDF generator to avoid DomPDF
        $this->app->instance(\App\Services\Pdf\FreeReservationPdfGenerator::class, new class extends \App\Services\Pdf\FreeReservationPdfGenerator {
            public function renderBinary(\App\Models\Reservation $reservation): string
            {
                return 'PDF';
            }
        });

        $this->post(route('panel_admin.free-reservation-requests.fulfill', ['freeReservationRequest' => $req->id], false), [
            'confirm' => 1,
        ])->assertRedirect();

        $this->assertSame(2, Reservation::query()->where('status', 'free')->count());
        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_FULFILLED, $req->status);
        $this->assertSame(1, FreeReservationRequestAttachment::query()->where('request_id', $req->id)->count());
        Storage::disk('local')->assertExists('free-reservation-requests/'.$req->id.'/doc.pdf');

        $res = Reservation::query()->orderBy('id')->get();
        $this->assertSame($d, $res[0]->reservation_date->toDateString());
        $this->assertSame($slotA->id, (int) $res[0]->drop_off_time_slot_id);
        $this->assertSame($slotB->id, (int) $res[0]->pick_up_time_slot_id);
        $this->assertTrue((bool) $res[0]->created_by_admin);
        $this->assertSame('KO111', $res[0]->license_plate);
        $this->assertSame('en', $res[0]->preferred_locale);
        foreach ($res as $row) {
            $this->assertSame($req->id, (int) $row->free_reservation_request_id);
            $this->assertSame(Reservation::EMAIL_SENT, (int) $row->email_sent);
            $this->assertNotNull($row->invoice_sent_at);
        }

        $alert = AdminAlert::query()->first();
        $this->assertNotNull($alert);
        $this->assertNotNull($alert->removed_at);

        Mail::assertSent(FreeReservationRequestFulfilledMail::class, function (FreeReservationRequestFulfilledMail $m): bool {
            return $m->hasTo('school@example.com')
                && count($m->pdfAttachments) === 2;
        });
        Mail::assertSent(FreeReservationRequestFulfilledMail::class, 1);
    }

    public function test_fulfill_does_not_create_any_reservations_when_final_availability_check_fails(): void
    {
        Mail::fake();

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $d = Carbon::now()->addDays(3)->toDateString();
        $slotA = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slotB = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        foreach ([$slotA, $slotB] as $s) {
            DailyParkingData::query()->create([
                'date' => $d,
                'time_slot_id' => $s->id,
                'capacity' => 1,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }

        $vt = VehicleType::query()->create(['price' => 10]);
        VehicleTypeTranslation::query()->create(['vehicle_type_id' => $vt->id, 'locale' => 'cg', 'name' => 'Autobus', 'description' => null]);

        $req = FreeReservationRequest::query()->create([
            'locale' => 'cg',
            'institution_name' => 'OŠ Kotor',
            'institution_email' => 'school@example.com',
            'institution_phone' => '+38267111222',
            'reservation_date' => $d,
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'country' => 'ME',
            'status' => FreeReservationRequest::STATUS_SUBMITTED,
        ]);
        $seg = FreeReservationRequestSegment::query()->create([
            'request_id' => $req->id,
            'reservation_date' => $d,
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'position' => 1,
        ]);
        FreeReservationRequestVehicle::query()->create(['request_id' => $req->id, 'segment_id' => $seg->id, 'license_plate' => 'KO111', 'vehicle_type_id' => $vt->id]);
        FreeReservationRequestVehicle::query()->create(['request_id' => $req->id, 'segment_id' => $seg->id, 'license_plate' => 'KO222', 'vehicle_type_id' => $vt->id]);

        $this->post(route('panel_admin.free-reservation-requests.fulfill', ['freeReservationRequest' => $req->id], false), [
            'confirm' => 1,
        ])->assertRedirect();

        $this->assertSame(0, Reservation::query()->count());
        $this->assertSame(1, FreeReservationRequest::query()->count());
    }

    public function test_fulfill_keeps_request_fulfilled_when_mail_pdf_fails_after_reservations_created(): void
    {
        Mail::fake();

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $d = Carbon::now()->addDays(3)->toDateString();
        $slotA = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slotB = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        foreach ([$slotA, $slotB] as $s) {
            DailyParkingData::query()->create([
                'date' => $d,
                'time_slot_id' => $s->id,
                'capacity' => 10,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }

        $vt = VehicleType::query()->create(['price' => 10]);
        VehicleTypeTranslation::query()->create(['vehicle_type_id' => $vt->id, 'locale' => 'cg', 'name' => 'Autobus', 'description' => null]);

        $req = FreeReservationRequest::query()->create([
            'locale' => 'cg',
            'institution_name' => 'OŠ Kotor',
            'institution_email' => 'school@example.com',
            'institution_phone' => '+38267111222',
            'reservation_date' => $d,
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'country' => 'ME',
            'status' => FreeReservationRequest::STATUS_SUBMITTED,
        ]);
        $seg = FreeReservationRequestSegment::query()->create([
            'request_id' => $req->id,
            'reservation_date' => $d,
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'position' => 1,
        ]);
        FreeReservationRequestVehicle::query()->create(['request_id' => $req->id, 'segment_id' => $seg->id, 'license_plate' => 'KO111', 'vehicle_type_id' => $vt->id]);

        AdminAlert::query()->create([
            'type' => 'free_reservation_request',
            'status' => AdminAlert::STATUS_UNREAD,
            'title' => 't',
            'message' => 'm',
            'payload_json' => ['free_reservation_request_id' => $req->id],
        ]);

        // Force PDF generator to fail
        $this->app->instance(\App\Services\Pdf\FreeReservationPdfGenerator::class, new class extends \App\Services\Pdf\FreeReservationPdfGenerator {
            public function renderBinary(\App\Models\Reservation $reservation): string
            {
                throw new \RuntimeException('pdf fail');
            }
        });

        $this->post(route('panel_admin.free-reservation-requests.fulfill', ['freeReservationRequest' => $req->id], false), [
            'confirm' => 1,
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(1, Reservation::query()->count());
        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_FULFILLED, $req->status);
        $this->assertSame($req->id, (int) Reservation::query()->value('free_reservation_request_id'));
        $this->assertSame(
            Reservation::EMAIL_NOT_SENT,
            (int) Reservation::query()->value('email_sent')
        );
        $alert = AdminAlert::query()->first();
        $this->assertNotNull($alert);
        $this->assertNotNull($alert->removed_at);
    }

    public function test_admin_can_preview_free_request_attachment_from_private_storage(): void
    {
        Storage::fake('local');

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $slotA = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slotB = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $req = FreeReservationRequest::query()->create([
            'locale' => 'cg',
            'institution_name' => 'Agencija',
            'institution_email' => 'agency@example.com',
            'institution_phone' => null,
            'reservation_date' => Carbon::now()->addDays(3)->toDateString(),
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'country' => 'ME',
            'status' => FreeReservationRequest::STATUS_SUBMITTED,
        ]);

        Storage::disk('local')->put('free-reservation-requests/'.$req->id.'/doc.pdf', '%PDF-1.4');
        $att = FreeReservationRequestAttachment::query()->create([
            'request_id' => $req->id,
            'original_name' => 'doc.pdf',
            'stored_path' => 'free-reservation-requests/'.$req->id.'/doc.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 8,
        ]);

        AdminAlert::query()->create([
            'type' => 'free_reservation_request',
            'status' => AdminAlert::STATUS_UNREAD,
            'title' => 't',
            'message' => 'm',
            'payload_json' => ['free_reservation_request_id' => $req->id],
        ]);

        $this->get(route('panel_admin.free-reservation-requests.attachments.preview', [
            'freeReservationRequest' => $req->id,
            'attachment' => $att->id,
        ], false))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff');

        $alert = AdminAlert::query()->first();
        $this->assertNotNull($alert);
        $this->assertSame(AdminAlert::STATUS_IN_PROGRESS, $alert->status);
    }

    public function test_admin_direct_free_reservation_blocked_for_duplicate_plate_slot(): void
    {
        Queue::fake();
        $admin = $this->seedAdmin();
        $date = Carbon::now()->addDay()->toDateString();
        [$date, $s1, $s2, $vt] = $this->seedSlotsAndVehicle($date);

        Reservation::query()->create([
            'merchant_transaction_id' => 'mt-existing-free',
            'drop_off_time_slot_id' => $s1->id,
            'pick_up_time_slot_id' => $s2->id,
            'reservation_date' => $date,
            'user_name' => 'Existing',
            'country' => 'ME',
            'license_plate' => 'KO123AB',
            'vehicle_type_id' => $vt->id,
            'email' => 'ex@example.com',
            'status' => 'paid',
            'invoice_amount' => '10.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
        ]);

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.free-reservations.store', [], false), [
                'reservation_date' => $date,
                'drop_off_time_slot_id' => $s1->id,
                'pick_up_time_slot_id' => $s2->id,
                'vehicle_type_id' => $vt->id,
                'name' => 'Škola Primjer',
                'country' => 'ME',
                'license_plate' => 'KO123AB',
                'email' => 'skola2@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, Reservation::query()->where('license_plate', 'KO123AB')->count());
    }

    /**
     * @return array{req: FreeReservationRequest, slotA: ListOfTimeSlot, slotB: ListOfTimeSlot, vt: VehicleType, date: string}
     */
    private function seedSubmittedFreeRequest(string $plate = 'KO-FRR-1'): array
    {
        $d = Carbon::now()->addDays(6)->toDateString();
        $slotA = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slotB = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        foreach ([$slotA, $slotB] as $s) {
            DailyParkingData::query()->create([
                'date' => $d,
                'time_slot_id' => $s->id,
                'capacity' => 10,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }

        $vt = VehicleType::query()->create(['price' => 10]);
        VehicleTypeTranslation::query()->create(['vehicle_type_id' => $vt->id, 'locale' => 'cg', 'name' => 'Autobus', 'description' => null]);

        $req = FreeReservationRequest::query()->create([
            'locale' => 'cg',
            'institution_name' => 'Institucija Test',
            'institution_email' => 'inst@example.com',
            'institution_phone' => '+38267111222',
            'reservation_date' => $d,
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'country' => 'ME',
            'status' => FreeReservationRequest::STATUS_SUBMITTED,
        ]);
        $seg = FreeReservationRequestSegment::query()->create([
            'request_id' => $req->id,
            'reservation_date' => $d,
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'position' => 1,
        ]);
        FreeReservationRequestVehicle::query()->create([
            'request_id' => $req->id,
            'segment_id' => $seg->id,
            'license_plate' => $plate,
            'vehicle_type_id' => $vt->id,
        ]);

        return ['req' => $req, 'slotA' => $slotA, 'slotB' => $slotB, 'vt' => $vt, 'date' => $d];
    }

    private function mockFreeReservationPdf(): void
    {
        $this->app->instance(\App\Services\Pdf\FreeReservationPdfGenerator::class, new class extends \App\Services\Pdf\FreeReservationPdfGenerator {
            public function renderBinary(\App\Models\Reservation $reservation): string
            {
                return 'PDF';
            }
        });
    }

    public function test_fulfill_second_click_is_idempotent_and_does_not_create_duplicates(): void
    {
        Mail::fake();
        Storage::fake('local');

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $seed = $this->seedSubmittedFreeRequest('KO-IDEM-1');
        $req = $seed['req'];
        $this->mockFreeReservationPdf();

        $route = route('panel_admin.free-reservation-requests.fulfill', ['freeReservationRequest' => $req->id], false);
        $this->post($route, ['confirm' => 1])->assertRedirect();
        $this->post($route, ['confirm' => 1])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(1, Reservation::query()->count());
        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_FULFILLED, $req->status);
        $this->assertSame($req->id, (int) Reservation::query()->value('free_reservation_request_id'));
    }

    public function test_fulfill_links_existing_orphan_reservation_and_completes_submitted_request(): void
    {
        Mail::fake();
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $seed = $this->seedSubmittedFreeRequest('KO-ORPH-1');
        $req = $seed['req'];
        $this->mockFreeReservationPdf();

        $orphan = Reservation::query()->create([
            'free_reservation_request_id' => null,
            'merchant_transaction_id' => (string) Str::uuid(),
            'drop_off_time_slot_id' => $seed['slotA']->id,
            'pick_up_time_slot_id' => $seed['slotB']->id,
            'reservation_date' => $seed['date'],
            'user_name' => 'Institucija Test',
            'country' => 'ME',
            'license_plate' => 'KO-ORPH-1',
            'vehicle_type_id' => $seed['vt']->id,
            'email' => 'inst@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => true,
        ]);

        AdminAlert::query()->create([
            'type' => 'free_reservation_request',
            'status' => AdminAlert::STATUS_UNREAD,
            'title' => 't',
            'message' => 'm',
            'payload_json' => ['free_reservation_request_id' => $req->id],
        ]);

        $this->post(route('panel_admin.free-reservation-requests.fulfill', ['freeReservationRequest' => $req->id], false), [
            'confirm' => 1,
        ])->assertRedirect()->assertSessionHas('status');

        $orphan->refresh();
        $req->refresh();
        $this->assertSame($req->id, (int) $orphan->free_reservation_request_id);
        $this->assertSame(FreeReservationRequest::STATUS_FULFILLED, $req->status);
        $this->assertSame(1, Reservation::query()->count());
        $this->assertNotNull(AdminAlert::query()->first()?->removed_at);
    }

    public function test_fulfill_relinks_reservation_wrongly_linked_to_another_request(): void
    {
        Mail::fake();
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $seed = $this->seedSubmittedFreeRequest('KO-WRONG-1');
        $req = $seed['req'];
        $this->mockFreeReservationPdf();

        $wrongOwner = FreeReservationRequest::query()->create([
            'locale' => 'cg',
            'institution_name' => 'Druga institucija',
            'institution_email' => 'other@example.com',
            'institution_phone' => '+38267111222',
            'reservation_date' => $seed['date'],
            'drop_off_time_slot_id' => $seed['slotA']->id,
            'pick_up_time_slot_id' => $seed['slotB']->id,
            'country' => 'ME',
            'status' => FreeReservationRequest::STATUS_FULFILLED,
        ]);

        $mislinked = Reservation::query()->create([
            'free_reservation_request_id' => $wrongOwner->id,
            'merchant_transaction_id' => (string) Str::uuid(),
            'drop_off_time_slot_id' => $seed['slotA']->id,
            'pick_up_time_slot_id' => $seed['slotB']->id,
            'reservation_date' => $seed['date'],
            'user_name' => 'Institucija Test',
            'country' => 'ME',
            'license_plate' => 'KO-WRONG-1',
            'vehicle_type_id' => $seed['vt']->id,
            'email' => 'inst@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => true,
        ]);

        $this->post(route('panel_admin.free-reservation-requests.fulfill', ['freeReservationRequest' => $req->id], false), [
            'confirm' => 1,
        ])->assertRedirect()->assertSessionHas('status');

        $mislinked->refresh();
        $this->assertSame($req->id, (int) $mislinked->free_reservation_request_id);
        $this->assertSame(FreeReservationRequest::STATUS_FULFILLED, $req->fresh()->status);
    }

    public function test_fulfill_blocks_when_matching_reservation_belongs_to_other_submitted_request(): void
    {
        Mail::fake();
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $d = Carbon::now()->addDays(7)->toDateString();
        $slotA = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slotB = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        foreach ([$slotA, $slotB] as $s) {
            DailyParkingData::query()->create([
                'date' => $d,
                'time_slot_id' => $s->id,
                'capacity' => 10,
                'reserved' => 0,
                'pending' => 0,
                'is_blocked' => false,
            ]);
        }

        $vt = VehicleType::query()->create(['price' => 10]);
        VehicleTypeTranslation::query()->create(['vehicle_type_id' => $vt->id, 'locale' => 'cg', 'name' => 'Autobus', 'description' => null]);

        $makeReq = function () use ($d, $slotA, $slotB, $vt): FreeReservationRequest {
            $req = FreeReservationRequest::query()->create([
                'locale' => 'cg',
                'institution_name' => 'Institucija Test',
                'institution_email' => 'inst@example.com',
                'institution_phone' => '+38267111222',
                'reservation_date' => $d,
                'drop_off_time_slot_id' => $slotA->id,
                'pick_up_time_slot_id' => $slotB->id,
                'country' => 'ME',
                'status' => FreeReservationRequest::STATUS_SUBMITTED,
            ]);
            $seg = FreeReservationRequestSegment::query()->create([
                'request_id' => $req->id,
                'reservation_date' => $d,
                'drop_off_time_slot_id' => $slotA->id,
                'pick_up_time_slot_id' => $slotB->id,
                'position' => 1,
            ]);
            FreeReservationRequestVehicle::query()->create([
                'request_id' => $req->id,
                'segment_id' => $seg->id,
                'license_plate' => 'KO-OTHER-1',
                'vehicle_type_id' => $vt->id,
            ]);

            return $req;
        };

        $otherReq = $makeReq();
        $targetReq = $makeReq();
        $this->mockFreeReservationPdf();

        Reservation::query()->create([
            'free_reservation_request_id' => $otherReq->id,
            'merchant_transaction_id' => (string) Str::uuid(),
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'reservation_date' => $d,
            'user_name' => 'Institucija Test',
            'country' => 'ME',
            'license_plate' => 'KO-OTHER-1',
            'vehicle_type_id' => $vt->id,
            'email' => 'inst@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => true,
        ]);

        $this->post(route('panel_admin.free-reservation-requests.fulfill', ['freeReservationRequest' => $targetReq->id], false), [
            'confirm' => 1,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(FreeReservationRequest::STATUS_SUBMITTED, $targetReq->fresh()->status);
    }

    public function test_fulfill_does_not_auto_link_ambiguous_matching_reservations(): void
    {
        Mail::fake();
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $seed = $this->seedSubmittedFreeRequest('KO-AMB-1');
        $req = $seed['req'];
        $this->mockFreeReservationPdf();

        foreach (['mt-a', 'mt-b'] as $mtid) {
            Reservation::query()->create([
                'free_reservation_request_id' => null,
                'merchant_transaction_id' => $mtid,
                'drop_off_time_slot_id' => $seed['slotA']->id,
                'pick_up_time_slot_id' => $seed['slotB']->id,
                'reservation_date' => $seed['date'],
                'user_name' => 'Institucija Test',
                'country' => 'ME',
                'license_plate' => 'KO-AMB-1',
                'vehicle_type_id' => $seed['vt']->id,
                'email' => 'inst@example.com',
                'status' => 'free',
                'invoice_amount' => '0.00',
                'email_sent' => Reservation::EMAIL_NOT_SENT,
                'created_by_admin' => true,
            ]);
        }

        $this->post(route('panel_admin.free-reservation-requests.fulfill', ['freeReservationRequest' => $req->id], false), [
            'confirm' => 1,
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(FreeReservationRequest::STATUS_SUBMITTED, $req->fresh()->status);
        $this->assertSame(2, Reservation::query()->count());
    }

    public function test_fulfilled_request_appears_in_approved_review_list(): void
    {
        Mail::fake();
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $seed = $this->seedSubmittedFreeRequest('KO-REVIEW-1');
        $req = $seed['req'];
        $this->mockFreeReservationPdf();

        $this->post(route('panel_admin.free-reservation-requests.fulfill', ['freeReservationRequest' => $req->id], false), [
            'confirm' => 1,
        ])->assertRedirect();

        $reviewDay = Carbon::now('Europe/Podgorica')->toDateString();
        $this->get(route('panel_admin.free-reservations', [
            'fzbr_review' => 'approved',
            'fzbr_date_from' => $reviewDay,
            'fzbr_date_to' => $reviewDay,
        ], false))
            ->assertOk()
            ->assertSee('Institucija Test', false);
    }

    public function test_repair_command_completes_submitted_request_with_existing_orphan(): void
    {
        Mail::fake();
        $seed = $this->seedSubmittedFreeRequest('KO-REPAIR-1');
        $req = $seed['req'];
        $this->mockFreeReservationPdf();

        Reservation::query()->create([
            'free_reservation_request_id' => null,
            'merchant_transaction_id' => (string) Str::uuid(),
            'drop_off_time_slot_id' => $seed['slotA']->id,
            'pick_up_time_slot_id' => $seed['slotB']->id,
            'reservation_date' => $seed['date'],
            'user_name' => 'Institucija Test',
            'country' => 'ME',
            'license_plate' => 'KO-REPAIR-1',
            'vehicle_type_id' => $seed['vt']->id,
            'email' => 'inst@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => true,
        ]);

        $this->artisan('free-reservation-requests:repair-fulfilled', ['--id' => $req->id])
            ->assertSuccessful();

        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_FULFILLED, $req->status);
        $this->assertSame($req->id, (int) Reservation::query()->value('free_reservation_request_id'));
    }

    public function test_fulfill_sends_confirmation_email_with_pdf_attachment(): void
    {
        Mail::fake();
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $seed = $this->seedSubmittedFreeRequest('KO-MAIL-1');
        $req = $seed['req'];
        $this->mockFreeReservationPdf();

        $this->post(route('panel_admin.free-reservation-requests.fulfill', ['freeReservationRequest' => $req->id], false), [
            'confirm' => 1,
        ])->assertRedirect();

        Mail::assertSent(FreeReservationRequestFulfilledMail::class, function (FreeReservationRequestFulfilledMail $m) use ($req): bool {
            return $m->hasTo($req->institution_email)
                && count($m->pdfAttachments) === 1
                && $m->pdfAttachments[0]['filename'] !== '';
        });
    }

    public function test_fulfill_linking_orphan_with_email_sent_already_does_not_fake_mail_sent(): void
    {
        Mail::fake();
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $seed = $this->seedSubmittedFreeRequest('KO-FAKE-SENT-1');
        $req = $seed['req'];
        $this->mockFreeReservationPdf();

        Reservation::query()->create([
            'free_reservation_request_id' => null,
            'merchant_transaction_id' => (string) Str::uuid(),
            'drop_off_time_slot_id' => $seed['slotA']->id,
            'pick_up_time_slot_id' => $seed['slotB']->id,
            'reservation_date' => $seed['date'],
            'user_name' => 'Institucija Test',
            'country' => 'ME',
            'license_plate' => 'KO-FAKE-SENT-1',
            'vehicle_type_id' => $seed['vt']->id,
            'email' => 'inst@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_SENT,
            'invoice_sent_at' => now(),
            'created_by_admin' => true,
        ]);

        $this->post(route('panel_admin.free-reservation-requests.fulfill', ['freeReservationRequest' => $req->id], false), [
            'confirm' => 1,
        ])->assertRedirect()->assertSessionHas('status');

        Mail::assertNothingSent();
        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_FULFILLED, $req->status);
    }

    public function test_repair_fulfilled_request_sends_missing_confirmation_email(): void
    {
        Mail::fake();
        $seed = $this->seedSubmittedFreeRequest('KO-REPAIR-MAIL-1');
        $req = $seed['req'];
        $this->mockFreeReservationPdf();

        $reservation = Reservation::query()->create([
            'free_reservation_request_id' => $req->id,
            'merchant_transaction_id' => (string) Str::uuid(),
            'drop_off_time_slot_id' => $seed['slotA']->id,
            'pick_up_time_slot_id' => $seed['slotB']->id,
            'reservation_date' => $seed['date'],
            'user_name' => 'Institucija Test',
            'country' => 'ME',
            'license_plate' => 'KO-REPAIR-MAIL-1',
            'vehicle_type_id' => $seed['vt']->id,
            'email' => 'inst@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => true,
        ]);
        $req->update(['status' => FreeReservationRequest::STATUS_FULFILLED]);

        $this->artisan('free-reservation-requests:repair-fulfilled', ['--id' => $req->id])
            ->assertSuccessful()
            ->expectsOutputToContain('mail_sent=yes');

        Mail::assertSent(FreeReservationRequestFulfilledMail::class, function (FreeReservationRequestFulfilledMail $m) use ($req): bool {
            return $m->hasTo($req->institution_email);
        });

        $reservation->refresh();
        $this->assertSame(Reservation::EMAIL_SENT, (int) $reservation->email_sent);
    }

    public function test_repair_fulfilled_request_skips_email_when_already_sent_without_resend_flag(): void
    {
        Mail::fake();
        $seed = $this->seedSubmittedFreeRequest('KO-REPAIR-SKIP-1');
        $req = $seed['req'];
        $this->mockFreeReservationPdf();

        Reservation::query()->create([
            'free_reservation_request_id' => $req->id,
            'merchant_transaction_id' => (string) Str::uuid(),
            'drop_off_time_slot_id' => $seed['slotA']->id,
            'pick_up_time_slot_id' => $seed['slotB']->id,
            'reservation_date' => $seed['date'],
            'user_name' => 'Institucija Test',
            'country' => 'ME',
            'license_plate' => 'KO-REPAIR-SKIP-1',
            'vehicle_type_id' => $seed['vt']->id,
            'email' => 'inst@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_SENT,
            'invoice_sent_at' => now(),
            'created_by_admin' => true,
        ]);
        $req->update(['status' => FreeReservationRequest::STATUS_FULFILLED]);

        $this->artisan('free-reservation-requests:repair-fulfilled', ['--id' => $req->id])
            ->assertSuccessful()
            ->expectsOutputToContain('mail_skipped=already_sent');

        Mail::assertNothingSent();
    }

    public function test_repair_fulfilled_request_resends_with_resend_email_flag(): void
    {
        Mail::fake();
        $seed = $this->seedSubmittedFreeRequest('KO-REPAIR-RESEND-1');
        $req = $seed['req'];
        $this->mockFreeReservationPdf();

        Reservation::query()->create([
            'free_reservation_request_id' => $req->id,
            'merchant_transaction_id' => (string) Str::uuid(),
            'drop_off_time_slot_id' => $seed['slotA']->id,
            'pick_up_time_slot_id' => $seed['slotB']->id,
            'reservation_date' => $seed['date'],
            'user_name' => 'Institucija Test',
            'country' => 'ME',
            'license_plate' => 'KO-REPAIR-RESEND-1',
            'vehicle_type_id' => $seed['vt']->id,
            'email' => 'inst@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_SENT,
            'invoice_sent_at' => now(),
            'created_by_admin' => true,
        ]);
        $req->update(['status' => FreeReservationRequest::STATUS_FULFILLED]);

        $this->artisan('free-reservation-requests:repair-fulfilled', [
            '--id' => $req->id,
            '--resend-email' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('mail_sent=yes');

        Mail::assertSent(FreeReservationRequestFulfilledMail::class, 1);
    }

    public function test_reject_request_does_not_send_fulfillment_confirmation_email(): void
    {
        Mail::fake();
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $seed = $this->seedSubmittedFreeRequest('KO-REJECT-1');
        $req = $seed['req'];

        $this->delete(route('panel_admin.free-reservation-requests.reject', ['freeReservationRequest' => $req->id], false), [
            'confirm' => 1,
        ])->assertRedirect();

        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_REJECTED, $req->status);
        Mail::assertNothingSent();
    }
}
