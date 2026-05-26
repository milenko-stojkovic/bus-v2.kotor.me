<?php

namespace Tests\Feature\Panel;

use App\Mail\AgencyFreeReservationRequestSubmittedMail;
use App\Models\AdminAlert;
use App\Models\FreeReservationRequest;
use App\Models\FreeReservationRequestAttachment;
use App\Models\FreeReservationRequestSegment;
use App\Models\FreeReservationRequestVehicle;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FzbrIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_does_not_show_students_branch_link_anymore(): void
    {
        $html = $this->get(route('landing', [], false))->assertOk()->getContent();

        $this->assertStringNotContainsString('/free-reservation-request', $html);
    }

    public function test_agency_panel_fzbr_submit_creates_request_snapshots_attachments_sends_admin_mail_and_creates_warning(): void
    {
        Storage::fake('local');
        Mail::fake();

        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);

        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);

        $vt = VehicleType::query()->create(['price' => 10]);
        VehicleTypeTranslation::query()->create(['vehicle_type_id' => $vt->id, 'locale' => 'cg', 'name' => 'Autobus', 'description' => null]);
        VehicleTypeTranslation::query()->create(['vehicle_type_id' => $vt->id, 'locale' => 'en', 'name' => 'Bus', 'description' => null]);

        $v1 = Vehicle::query()->create(['user_id' => $user->id, 'license_plate' => 'KO111AA', 'vehicle_type_id' => $vt->id]);
        $v2 = Vehicle::query()->create(['user_id' => $user->id, 'license_plate' => 'KO222BB', 'vehicle_type_id' => $vt->id]);

        $d = now()->addDays(3)->toDateString();

        $this->post(route('panel.fzbr.store', [], false), [
            'reservation_date' => $d,
            'segments' => [
                [
                    'drop_off_time_slot_id' => $drop->id,
                    'pick_up_time_slot_id' => $pick->id,
                    'vehicles' => [$v1->id],
                ],
                [
                    'drop_off_time_slot_id' => $drop->id,
                    'pick_up_time_slot_id' => $pick->id,
                    'vehicles' => [$v2->id],
                ],
            ],
            'documents' => [
                UploadedFile::fake()->create('osnov.pdf', 120, 'application/pdf'),
                UploadedFile::fake()->image('dokaz.jpg', 800, 600),
            ],
            'accept_privacy' => 1,
        ])->assertRedirect(route('panel.fzbr.create', [], false));

        $this->assertSame(0, Reservation::query()->count());

        $req = FreeReservationRequest::query()->first();
        $this->assertNotNull($req);
        $this->assertSame($user->id, $req->user_id);
        $this->assertSame($user->name, $req->institution_name);
        $this->assertSame($user->email, $req->institution_email);
        $this->assertNull($req->institution_phone);
        $this->assertSame($d, $req->reservation_date->toDateString());

        $segments = FreeReservationRequestSegment::query()->where('request_id', $req->id)->orderBy('position')->get();
        $this->assertCount(2, $segments);

        $snapVehicles = FreeReservationRequestVehicle::query()->where('request_id', $req->id)->orderBy('id')->get();
        $this->assertCount(2, $snapVehicles);
        $this->assertNotNull($snapVehicles[0]->segment_id);
        $this->assertNotNull($snapVehicles[1]->segment_id);
        $this->assertNotSame((int) $snapVehicles[0]->segment_id, (int) $snapVehicles[1]->segment_id);
        $this->assertSame($v1->id, (int) $snapVehicles[0]->agency_vehicle_id);
        $this->assertSame('KO111AA', $snapVehicles[0]->license_plate);
        $this->assertSame($vt->id, (int) $snapVehicles[0]->vehicle_type_id);
        $this->assertNotEmpty($snapVehicles[0]->vehicle_type_label);

        $atts = FreeReservationRequestAttachment::query()->where('request_id', $req->id)->get();
        $this->assertCount(2, $atts);
        foreach ($atts as $a) {
            Storage::disk('local')->assertExists($a->stored_path);
        }

        $alert = AdminAlert::query()->where('type', 'free_reservation_request')->first();
        $this->assertNotNull($alert);
        $this->assertSame('free_reservation_request:'.$req->id, $alert->payload_json['dedupe_key'] ?? null);

        Mail::assertSent(AgencyFreeReservationRequestSubmittedMail::class, function (AgencyFreeReservationRequestSubmittedMail $m) use ($req) {
            return $m->hasTo('bus@kotor.me') && $m->request->id === $req->id;
        });

        $this->assertStringContainsString($user->email, $alert->message);
        $this->assertSame($req->id, $alert->payload_json['free_reservation_request_id'] ?? null);
        $this->assertSame('agency_panel_fzbr', $alert->payload_json['source'] ?? null);
    }

    public function test_agency_fzbr_submit_creates_unnotified_alert_when_mail_fails(): void
    {
        Storage::fake('local');
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Mail\Events\MessageSending::class,
            static fn () => throw new \RuntimeException('mail transport simulated failure')
        );

        $user = User::factory()->create(['lang' => 'cg', 'country' => 'ME']);
        $this->actingAs($user);

        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);

        $vt = VehicleType::query()->create(['price' => 10]);
        VehicleTypeTranslation::query()->create(['vehicle_type_id' => $vt->id, 'locale' => 'cg', 'name' => 'Autobus', 'description' => null]);
        VehicleTypeTranslation::query()->create(['vehicle_type_id' => $vt->id, 'locale' => 'en', 'name' => 'Bus', 'description' => null]);

        $v1 = Vehicle::query()->create(['user_id' => $user->id, 'license_plate' => 'KO111AA', 'vehicle_type_id' => $vt->id]);

        $d = now()->addDays(3)->toDateString();

        $this->post(route('panel.fzbr.store', [], false), [
            'reservation_date' => $d,
            'segments' => [
                [
                    'drop_off_time_slot_id' => $drop->id,
                    'pick_up_time_slot_id' => $pick->id,
                    'vehicles' => [$v1->id],
                ],
            ],
            'documents' => [
                UploadedFile::fake()->create('osnov.pdf', 120, 'application/pdf'),
            ],
            'accept_privacy' => 1,
        ])->assertRedirect(route('panel.fzbr.create', [], false));

        $req = FreeReservationRequest::query()->first();
        $this->assertNotNull($req);

        $this->assertDatabaseHas('admin_alerts', [
            'type' => 'free_reservation_request',
        ]);
        $this->assertDatabaseHas('admin_alerts', [
            'type' => 'fzbr_request_unnotified',
        ]);

        $un = AdminAlert::query()->where('type', 'fzbr_request_unnotified')->first();
        $this->assertNotNull($un);
        $this->assertSame('high', $un->payload_json['severity'] ?? null);
        $this->assertSame(
            'free_reservation_request:'.$req->id.':unnotified',
            $un->payload_json['dedupe_key'] ?? null
        );
    }

    public function test_fzbr_page_shows_english_segment_labels_when_locale_is_en(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'lang' => 'en',
            'country' => 'ME',
        ]);
        $this->actingAs($user)
            ->withSession(['locale' => 'en']);

        $html = $this->get(route('panel.fzbr.create', [], false))->assertOk()->getContent();

        $this->assertStringContainsString('Arrival and departure', $html);
        $this->assertStringContainsString('Maximum number of vehicles per segment:', $html);
        $this->assertStringNotContainsString('Dolazak i odlazak', $html);
        $this->assertStringNotContainsString('Maksimalan broj vozila po segmentu', $html);
    }
}

