<?php

namespace Tests\Feature\Vehicles;

use App\Models\Admin;
use App\Models\FreeReservationRequest;
use App\Models\FreeReservationRequestVehicle;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategoryChangeRequest;
use App\Models\VehicleType;
use App\Services\Vehicle\VehiclePlateCategoryConsistency;
use App\Services\Vehicle\VehiclePlateLock;
use App\Services\Vehicle\VehiclePlateLockNotAcquired;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class VehiclePlateCategoryConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_agency_can_add_the_same_plate_in_the_same_category(): void
    {
        [$a] = $this->seedTypes();
        $first = User::factory()->create();
        $second = User::factory()->create();
        Vehicle::query()->create([
            'user_id' => $first->id,
            'license_plate' => 'KO111',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);

        $this->actingAs($second)->post(route('panel.vehicles.store', [], false), [
            'license_plate' => 'ko-111',
            'vehicle_type_id' => $a->id,
        ])->assertRedirect(route('panel.vehicles', [], false));

        $this->assertSame(2, Vehicle::query()->where('license_plate', 'KO111')->where('vehicle_type_id', $a->id)->count());
        $this->assertTrue(Vehicle::query()->where('user_id', $second->id)->where('license_plate', 'KO111')->where('status', Vehicle::STATUS_ACTIVE)->exists());
    }

    public function test_another_agency_cannot_add_the_same_plate_in_a_different_category(): void
    {
        [$a, $b] = $this->seedTypes();
        $first = User::factory()->create();
        $second = User::factory()->create(['lang' => 'en']);
        Vehicle::query()->create([
            'user_id' => $first->id,
            'license_plate' => 'KO222',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);
        $reservation = $this->reservationFor($first, 'KO222', $a->id);

        $this->actingAs($second)->withSession(['locale' => 'en'])->post(route('panel.vehicles.store', [], false), [
            'license_plate' => 'KO 222',
            'vehicle_type_id' => $b->id,
        ])->assertRedirect(route('panel.vehicles', [], false))
            ->assertSessionHas('error', 'This registration plate is already recorded in another category. Choose one option. The category you entered is not applied automatically.');

        $choice = session('category_change_needed');
        $this->assertIsArray($choice);
        $this->assertSame((int) $a->id, (int) $choice['recorded_vehicle_type_id']);
        $this->assertSame((int) $b->id, (int) $choice['submitted_vehicle_type_id']);
        $this->assertNull($choice['old_vehicle_id']);
        $this->assertSame(1, Vehicle::query()->where('license_plate', 'KO222')->count());
        $this->assertFalse(Vehicle::query()->where('user_id', $second->id)->exists());
        $reservation->refresh();
        $this->assertSame((int) $a->id, (int) $reservation->vehicle_type_id);
        $this->assertSame('10.00', (string) $reservation->invoice_amount);
    }

    public function test_existing_conflicting_categories_block_a_new_addition_and_keep_current_records(): void
    {
        [$a, $b] = $this->seedTypes();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $third = User::factory()->create(['lang' => 'cg']);
        $active = Vehicle::query()->create([
            'user_id' => $first->id,
            'license_plate' => 'KO333',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $removed = Vehicle::query()->create([
            'user_id' => $second->id,
            'license_plate' => 'KO333',
            'vehicle_type_id' => $b->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);

        $this->actingAs($third)->withSession(['locale' => 'cg'])->post(route('panel.vehicles.store', [], false), [
            'license_plate' => 'KO333',
            'vehicle_type_id' => $a->id,
        ])->assertSessionHas('error', 'Ova registarska tablica već ima neusaglašene kategorije vozila. Novo vozilo sa ovom tablicom trenutno nije moguće dodati. Pišite na bus@kotor.me.');

        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $active->refresh()->status);
        $this->assertSame((int) $a->id, (int) $active->vehicle_type_id);
        $this->assertSame(Vehicle::STATUS_REMOVED, (string) $removed->refresh()->status);
        $this->assertSame((int) $b->id, (int) $removed->vehicle_type_id);
        $this->assertFalse(Vehicle::query()->where('user_id', $third->id)->exists());

        $this->actingAs($first)->get(route('panel.vehicles', [], false))
            ->assertOk()
            ->assertSee('KO333', false);
    }

    public function test_conflicted_plate_cannot_be_reactivated(): void
    {
        [$a, $b] = $this->seedTypes();
        $owner = User::factory()->create(['lang' => 'en']);
        $other = User::factory()->create();
        $removed = Vehicle::query()->create([
            'user_id' => $owner->id,
            'license_plate' => 'KO334',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);
        $active = Vehicle::query()->create([
            'user_id' => $other->id,
            'license_plate' => 'KO334',
            'vehicle_type_id' => $b->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $reservation = $this->reservationFor($owner, 'KO334', $a->id, (int) $removed->id);

        $this->actingAs($owner)->withSession(['locale' => 'en'])->post(route('panel.vehicles.store', [], false), [
            'license_plate' => 'KO334',
            'vehicle_type_id' => $a->id,
        ])->assertSessionHas('error', 'This registration plate already has conflicting vehicle categories. A new vehicle with this plate cannot be added right now. Write to bus@kotor.me.');

        $this->assertSame(Vehicle::STATUS_REMOVED, (string) $removed->refresh()->status);
        $this->assertSame((int) $a->id, (int) $removed->vehicle_type_id);
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $active->refresh()->status);
        $this->assertSame((int) $b->id, (int) $active->vehicle_type_id);
        $reservation->refresh();
        $this->assertSame((int) $a->id, (int) $reservation->vehicle_type_id);
        $this->assertSame('10.00', (string) $reservation->invoice_amount);
    }

    public function test_same_agency_cannot_add_a_second_active_row_for_the_same_plate(): void
    {
        [$a] = $this->seedTypes();
        $user = User::factory()->create();
        Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO444',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);

        $this->actingAs($user)->post(route('panel.vehicles.store', [], false), [
            'license_plate' => 'KO444',
            'vehicle_type_id' => $a->id,
        ])->assertSessionHasErrors('license_plate');

        $this->assertSame(1, Vehicle::query()->where('user_id', $user->id)->where('license_plate', 'KO444')->count());
    }

    public function test_vehicle_form_uses_the_canonical_plate_normalizer(): void
    {
        [$a] = $this->seedTypes();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('panel.vehicles.store', [], false), [
            'license_plate' => 'KOŽ-1',
            'vehicle_type_id' => $a->id,
        ])->assertRedirect();

        $this->assertSame('KOZ1', VehiclePlateCategoryConsistency::normalize('KOŽ-1'));
        $this->assertTrue(Vehicle::query()->where('user_id', $user->id)->where('license_plate', 'KOZ1')->exists());
    }

    public function test_direct_vehicle_update_cannot_introduce_a_category_conflict(): void
    {
        [$a, $b] = $this->seedTypes();
        $owner = User::factory()->create(['lang' => 'en']);
        $other = User::factory()->create();
        $vehicle = Vehicle::query()->create([
            'user_id' => $owner->id,
            'license_plate' => 'KO555',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        Vehicle::query()->create([
            'user_id' => $other->id,
            'license_plate' => 'KO555',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);

        $this->actingAs($owner)->withSession(['locale' => 'en'])->patch(route('panel.vehicles.update', $vehicle, false), [
            'license_plate' => 'KO555',
            'vehicle_type_id' => $b->id,
        ])->assertSessionHas('error');

        $this->assertSame((int) $a->id, (int) $vehicle->refresh()->vehicle_type_id);
    }

    public function test_approval_updates_every_matching_row_and_does_not_rewrite_snapshots(): void
    {
        [$a, $b] = $this->seedTypes();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $old = Vehicle::query()->create([
            'user_id' => $owner->id,
            'license_plate' => 'KO666',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);
        $otherVehicle = Vehicle::query()->create([
            'user_id' => $other->id,
            'license_plate' => 'KO666',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $reservation = $this->reservationFor($owner, 'KO666', $a->id, (int) $old->id);
        $slot = ListOfTimeSlot::query()->create(['time_slot' => '09:00 - 09:20']);
        $free = FreeReservationRequest::query()->create([
            'locale' => 'en',
            'institution_name' => 'Skola',
            'institution_email' => 'skola@example.com',
            'institution_phone' => '000',
            'reservation_date' => now()->addDays(4)->toDateString(),
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'country' => 'ME',
            'status' => FreeReservationRequest::STATUS_SUBMITTED,
        ]);
        $freeVehicle = FreeReservationRequestVehicle::query()->create([
            'request_id' => $free->id,
            'license_plate' => 'KO666',
            'vehicle_type_id' => $a->id,
            'vehicle_type_label' => 'Snapshot label',
        ]);
        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-snap-ko666',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => now()->addDays(4)->toDateString(),
            'user_name' => 'Agency',
            'country' => 'ME',
            'license_plate' => 'KO666',
            'vehicle_type_id' => $a->id,
            'invoice_amount_snapshot' => '10.00',
            'email' => 'pay@example.com',
            'status' => TempData::STATUS_PENDING,
        ]);
        $change = VehicleCategoryChangeRequest::query()->create([
            'user_id' => $owner->id,
            'old_vehicle_id' => $old->id,
            'license_plate' => 'KO666',
            'old_vehicle_type_id' => $a->id,
            'requested_vehicle_type_id' => $b->id,
            'status' => VehicleCategoryChangeRequest::STATUS_PENDING,
            'document_original_name' => 'doc.pdf',
            'document_path' => 'tmp',
            'document_mime_type' => 'application/pdf',
            'document_size_bytes' => 10,
            'locale' => 'cg',
        ]);
        $admin = Admin::query()->create([
            'username' => 'plate-admin',
            'email' => 'plate-admin@example.com',
            'password' => bcrypt('secret-password-plate'),
            'control_access' => false,
            'admin_access' => true,
        ]);

        $third = User::factory()->create();
        $removedOther = Vehicle::query()->create([
            'user_id' => $third->id,
            'license_plate' => 'KO666',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);

        $this->actingAs($admin, 'panel_admin')
            ->from('/admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', [
                'user' => $owner->id,
                'request' => $change->id,
            ], false))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame((int) $b->id, (int) $old->refresh()->vehicle_type_id);
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $old->status);
        $this->assertSame((int) $b->id, (int) $otherVehicle->refresh()->vehicle_type_id);
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $otherVehicle->status);
        $this->assertSame((int) $b->id, (int) $removedOther->refresh()->vehicle_type_id);
        $this->assertSame(Vehicle::STATUS_REMOVED, (string) $removedOther->status);
        $this->assertSame(VehicleCategoryChangeRequest::STATUS_APPROVED, (string) $change->refresh()->status);
        $reservation->refresh();
        $this->assertSame((int) $a->id, (int) $reservation->vehicle_type_id);
        $this->assertSame('10.00', (string) $reservation->invoice_amount);
        $this->assertSame('paid', (string) $reservation->status);
        $freeVehicle->refresh();
        $temp->refresh();
        $this->assertSame((int) $a->id, (int) $freeVehicle->vehicle_type_id);
        $this->assertSame('Snapshot label', (string) $freeVehicle->vehicle_type_label);
        $this->assertSame((int) $a->id, (int) $temp->vehicle_type_id);
        $this->assertSame('10.00', (string) $temp->invoice_amount_snapshot);
    }

    public function test_both_choices_are_explicit_and_the_recorded_category_is_not_substituted_silently(): void
    {
        [$a, $b] = $this->seedTypes();
        $other = User::factory()->create(['name' => 'Secret Operator', 'email' => 'secret-operator@example.com']);
        $agency = User::factory()->create(['lang' => 'en', 'name' => 'Choosing Agency']);
        $removed = Vehicle::query()->create([
            'user_id' => $agency->id,
            'license_plate' => 'KO777',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);
        Vehicle::query()->create([
            'user_id' => $other->id,
            'license_plate' => 'KO777',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);

        $this->actingAs($agency)->withSession(['locale' => 'en'])->post(route('panel.vehicles.store', [], false), [
            'license_plate' => 'KO777',
            'vehicle_type_id' => $b->id,
        ])->assertRedirect(route('panel.vehicles', [], false));

        $html = $this->get(route('panel.vehicles', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('Add the vehicle in the recorded category', $html);
        $this->assertStringContainsString('No document is required.', $html);
        $this->assertStringContainsString('Request a category correction', $html);
        $this->assertStringContainsString('name="vehicle_type_id" value="'.$a->id.'"', $html);
        $this->assertStringContainsString('name="requested_vehicle_type_id" value="'.$b->id.'"', $html);
        $this->assertStringNotContainsString('Secret Operator', $html);
        $this->assertStringNotContainsString('secret-operator@example.com', $html);
        $this->assertStringNotContainsString('ownership', $html);
        $this->assertStringNotContainsString('ugovor', $html);
        $this->assertStringNotContainsString('saglasnost', $html);
        $this->assertSame(Vehicle::STATUS_REMOVED, (string) $removed->refresh()->status);

        $this->post(route('panel.vehicles.store', [], false), [
            'license_plate' => 'KO777',
            'vehicle_type_id' => $b->id,
            'category_choice' => 'use_recorded',
            'expected_recorded_vehicle_type_id' => $a->id,
        ])->assertSessionHas('error', 'The recorded category for this plate changed. Nothing was saved. Choose again.');

        $this->assertSame(Vehicle::STATUS_REMOVED, (string) $removed->refresh()->status);
        $this->assertSame(0, VehicleCategoryChangeRequest::query()->count());
        $this->assertSame(2, Vehicle::query()->where('license_plate', 'KO777')->count());
    }

    public function test_recorded_category_choice_reactivates_without_documents(): void
    {
        [$a, $b] = $this->seedTypes();
        $agency = User::factory()->create(['lang' => 'en']);
        $removed = Vehicle::query()->create([
            'user_id' => $agency->id,
            'license_plate' => 'KO778',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);

        $this->actingAs($agency)->withSession(['locale' => 'en'])->post(route('panel.vehicles.store', [], false), [
            'license_plate' => 'KO778',
            'vehicle_type_id' => $a->id,
            'category_choice' => 'use_recorded',
            'expected_recorded_vehicle_type_id' => $a->id,
        ])->assertRedirect(route('panel.vehicles', [], false));

        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $removed->refresh()->status);
        $this->assertSame((int) $a->id, (int) $removed->vehicle_type_id);
        $this->assertSame(0, VehicleCategoryChangeRequest::query()->count());
        $this->assertFalse(Vehicle::query()->where('license_plate', 'KO778')->where('vehicle_type_id', $b->id)->exists());
    }

    public function test_correction_without_an_existing_row_stores_null_old_vehicle_and_approval_inserts_only_the_requester(): void
    {
        [$a, $b] = $this->seedTypes();
        $other = User::factory()->create();
        $agency = User::factory()->create(['lang' => 'en', 'email' => 'new-agency@example.com', 'name' => 'New Agency']);
        $active = Vehicle::query()->create([
            'user_id' => $other->id,
            'license_plate' => 'KO779',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $removed = Vehicle::query()->create([
            'user_id' => User::factory()->create()->id,
            'license_plate' => 'KO779',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);
        Storage::fake('local');

        $this->actingAs($agency)->post(route('panel.vehicles.category_change_requests.store', [], false), [
            'license_plate' => 'KO779',
            'old_vehicle_type_id' => $a->id,
            'requested_vehicle_type_id' => $b->id,
            'documents' => [UploadedFile::fake()->create('licence.pdf', 20, 'application/pdf')],
        ])->assertRedirect(route('panel.vehicles', [], false));

        $req = VehicleCategoryChangeRequest::query()->firstOrFail();
        $this->assertNull($req->old_vehicle_id);
        $this->assertSame(VehicleCategoryChangeRequest::STATUS_PENDING, (string) $req->status);

        $this->post(route('panel.vehicles.category_change_requests.store', [], false), [
            'license_plate' => 'KO779',
            'old_vehicle_type_id' => $a->id,
            'requested_vehicle_type_id' => $b->id,
            'documents' => [UploadedFile::fake()->create('licence-2.pdf', 20, 'application/pdf')],
        ])->assertRedirect(route('panel.vehicles', [], false));
        $this->assertSame(1, VehicleCategoryChangeRequest::query()->count());

        $admin = Admin::query()->create([
            'username' => 'choice-admin',
            'email' => 'choice-admin@example.com',
            'password' => bcrypt('secret-password-choice'),
            'control_access' => false,
            'admin_access' => true,
        ]);
        \Illuminate\Support\Facades\Queue::fake();

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', [
                'user' => $agency->id,
                'request' => $req->id,
            ], false))
            ->assertRedirect();

        $created = Vehicle::query()->where('user_id', $agency->id)->where('license_plate', 'KO779')->firstOrFail();
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $created->status);
        $this->assertSame((int) $b->id, (int) $created->vehicle_type_id);
        $this->assertSame(1, Vehicle::query()->where('user_id', $agency->id)->where('license_plate', 'KO779')->count());
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $active->refresh()->status);
        $this->assertSame((int) $b->id, (int) $active->vehicle_type_id);
        $this->assertSame(Vehicle::STATUS_REMOVED, (string) $removed->refresh()->status);
        $this->assertSame((int) $b->id, (int) $removed->vehicle_type_id);
        $this->assertSame(VehicleCategoryChangeRequest::STATUS_APPROVED, (string) $req->refresh()->status);
    }

    public function test_stale_correction_and_inconsistent_approval_write_nothing(): void
    {
        [$a, $b] = $this->seedTypes();
        $other = User::factory()->create();
        $agency = User::factory()->create(['lang' => 'en']);
        $recorded = Vehicle::query()->create([
            'user_id' => $other->id,
            'license_plate' => 'KO780',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        Storage::fake('local');

        $recorded->update(['vehicle_type_id' => $b->id]);

        $this->actingAs($agency)->withSession(['locale' => 'en'])->post(route('panel.vehicles.category_change_requests.store', [], false), [
            'license_plate' => 'KO780',
            'old_vehicle_type_id' => $a->id,
            'requested_vehicle_type_id' => $b->id,
            'documents' => [UploadedFile::fake()->create('late.pdf', 20, 'application/pdf')],
        ])->assertSessionHas('error', 'The recorded category for this plate changed. Nothing was saved. Choose again.');

        $this->assertSame(0, VehicleCategoryChangeRequest::query()->count());
        $this->assertFalse(Vehicle::query()->where('user_id', $agency->id)->exists());

        $conflicted = Vehicle::query()->create([
            'user_id' => $agency->id,
            'license_plate' => 'KO781',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);
        Vehicle::query()->create([
            'user_id' => $other->id,
            'license_plate' => 'KO781',
            'vehicle_type_id' => $b->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $pending = VehicleCategoryChangeRequest::query()->create([
            'user_id' => $agency->id,
            'old_vehicle_id' => $conflicted->id,
            'license_plate' => 'KO781',
            'old_vehicle_type_id' => $a->id,
            'requested_vehicle_type_id' => $b->id,
            'status' => VehicleCategoryChangeRequest::STATUS_PENDING,
            'document_original_name' => 'doc.pdf',
            'document_path' => 'tmp',
            'document_mime_type' => 'application/pdf',
            'document_size_bytes' => 10,
            'locale' => 'cg',
        ]);
        $admin = Admin::query()->create([
            'username' => 'stale-admin',
            'email' => 'stale-admin@example.com',
            'password' => bcrypt('secret-password-stale'),
            'control_access' => false,
            'admin_access' => true,
        ]);

        $this->actingAs($admin, 'panel_admin')
            ->from('/admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', [
                'user' => $agency->id,
                'request' => $pending->id,
            ], false))
            ->assertSessionHasErrors('vehicle');

        $this->assertSame(VehicleCategoryChangeRequest::STATUS_PENDING, (string) $pending->refresh()->status);
        $this->assertSame(Vehicle::STATUS_REMOVED, (string) $conflicted->refresh()->status);
        $this->assertSame((int) $a->id, (int) $conflicted->vehicle_type_id);
    }

    public function test_rejection_with_null_old_vehicle_does_not_create_a_vehicle(): void
    {
        [$a, $b] = $this->seedTypes();
        $agency = User::factory()->create();
        $pending = VehicleCategoryChangeRequest::query()->create([
            'user_id' => $agency->id,
            'old_vehicle_id' => null,
            'license_plate' => 'KO782',
            'old_vehicle_type_id' => $a->id,
            'requested_vehicle_type_id' => $b->id,
            'status' => VehicleCategoryChangeRequest::STATUS_PENDING,
            'document_original_name' => 'doc.pdf',
            'document_path' => 'tmp',
            'document_mime_type' => 'application/pdf',
            'document_size_bytes' => 10,
            'locale' => 'cg',
        ]);
        $admin = Admin::query()->create([
            'username' => 'reject-admin',
            'email' => 'reject-admin@example.com',
            'password' => bcrypt('secret-password-reject'),
            'control_access' => false,
            'admin_access' => true,
        ]);

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.reject', [
                'user' => $agency->id,
                'request' => $pending->id,
            ], false), [
                'reason' => 'Fotografija nije čitljiva.',
            ])
            ->assertRedirect();

        $this->assertSame(VehicleCategoryChangeRequest::STATUS_REJECTED, (string) $pending->refresh()->status);
        $this->assertFalse(Vehicle::query()->where('license_plate', 'KO782')->exists());
    }

    public function test_active_vehicle_correction_stays_active_until_approval_updates_every_row(): void
    {
        [$a, $b] = $this->seedTypes();
        $owner = User::factory()->create(['lang' => 'en', 'email' => 'active-owner@example.com', 'name' => 'Active Owner']);
        $other = User::factory()->create(['name' => 'Hidden Agency']);
        $third = User::factory()->create();
        $active = Vehicle::query()->create([
            'user_id' => $owner->id,
            'license_plate' => 'KO790',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $otherActive = Vehicle::query()->create([
            'user_id' => $other->id,
            'license_plate' => 'KO790',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $otherRemoved = Vehicle::query()->create([
            'user_id' => $third->id,
            'license_plate' => 'KO790',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);
        $reservation = $this->reservationFor($owner, 'KO790', $a->id, (int) $active->id);
        Storage::fake('local');

        $html = $this->actingAs($owner)->withSession(['locale' => 'en'])
            ->get(route('panel.vehicles', [], false))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Correct category', $html);
        $this->assertStringContainsString('The vehicle stays available in its current category while the administrator reviews the request.', $html);
        $this->assertStringContainsString('name="old_vehicle_id" value="'.$active->id.'"', $html);
        $this->assertStringNotContainsString('Hidden Agency', $html);
        $this->assertStringNotContainsString('ownership', $html);

        $file = UploadedFile::fake()->create('licence.pdf', 20, 'application/pdf');
        $this->post(route('panel.vehicles.category_change_requests.store', [], false), [
            'old_vehicle_id' => $active->id,
            'license_plate' => 'KO790',
            'old_vehicle_type_id' => $a->id,
            'requested_vehicle_type_id' => $b->id,
            'documents' => [$file],
        ])->assertRedirect(route('panel.vehicles', [], false));

        $req = VehicleCategoryChangeRequest::query()->firstOrFail();
        $this->assertSame((int) $active->id, (int) $req->old_vehicle_id);
        $this->assertSame(VehicleCategoryChangeRequest::STATUS_PENDING, (string) $req->status);
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $active->refresh()->status);
        $this->assertSame((int) $a->id, (int) $active->vehicle_type_id);

        $this->post(route('panel.vehicles.category_change_requests.store', [], false), [
            'old_vehicle_id' => $active->id,
            'license_plate' => 'KO790',
            'old_vehicle_type_id' => $a->id,
            'requested_vehicle_type_id' => $b->id,
            'documents' => [$file],
        ])->assertRedirect(route('panel.vehicles', [], false));
        $this->assertSame(1, VehicleCategoryChangeRequest::query()->count());

        $admin = Admin::query()->create([
            'username' => 'active-admin',
            'email' => 'active-admin@example.com',
            'password' => bcrypt('secret-password-active'),
            'control_access' => false,
            'admin_access' => true,
        ]);
        \Illuminate\Support\Facades\Queue::fake();

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', [
                'user' => $owner->id,
                'request' => $req->id,
            ], false))
            ->assertRedirect();

        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $active->refresh()->status);
        $this->assertSame((int) $b->id, (int) $active->vehicle_type_id);
        $this->assertSame(1, Vehicle::query()->where('user_id', $owner->id)->where('license_plate', 'KO790')->count());
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $otherActive->refresh()->status);
        $this->assertSame((int) $b->id, (int) $otherActive->vehicle_type_id);
        $this->assertSame(Vehicle::STATUS_REMOVED, (string) $otherRemoved->refresh()->status);
        $this->assertSame((int) $b->id, (int) $otherRemoved->vehicle_type_id);
        $reservation->refresh();
        $this->assertSame((int) $a->id, (int) $reservation->vehicle_type_id);
        $this->assertSame('10.00', (string) $reservation->invoice_amount);
        $this->assertSame('paid', (string) $reservation->status);
    }

    public function test_rejection_of_an_active_correction_leaves_every_vehicle_unchanged(): void
    {
        [$a, $b] = $this->seedTypes();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $active = Vehicle::query()->create([
            'user_id' => $owner->id,
            'license_plate' => 'KO791',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $otherActive = Vehicle::query()->create([
            'user_id' => $other->id,
            'license_plate' => 'KO791',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $pending = VehicleCategoryChangeRequest::query()->create([
            'user_id' => $owner->id,
            'old_vehicle_id' => $active->id,
            'license_plate' => 'KO791',
            'old_vehicle_type_id' => $a->id,
            'requested_vehicle_type_id' => $b->id,
            'status' => VehicleCategoryChangeRequest::STATUS_PENDING,
            'document_original_name' => 'doc.pdf',
            'document_path' => 'tmp',
            'document_mime_type' => 'application/pdf',
            'document_size_bytes' => 10,
            'locale' => 'cg',
        ]);
        $admin = Admin::query()->create([
            'username' => 'active-reject',
            'email' => 'active-reject@example.com',
            'password' => bcrypt('secret-password-active-reject'),
            'control_access' => false,
            'admin_access' => true,
        ]);

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.reject', [
                'user' => $owner->id,
                'request' => $pending->id,
            ], false), [
                'reason' => 'Fotografija nije čitljiva.',
            ])
            ->assertRedirect();

        $this->assertSame(VehicleCategoryChangeRequest::STATUS_REJECTED, (string) $pending->refresh()->status);
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $active->refresh()->status);
        $this->assertSame((int) $a->id, (int) $active->vehicle_type_id);
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $otherActive->refresh()->status);
        $this->assertSame((int) $a->id, (int) $otherActive->vehicle_type_id);
    }

    public function test_conflicted_active_plate_cannot_start_an_ordinary_correction(): void
    {
        [$a, $b] = $this->seedTypes();
        $owner = User::factory()->create(['lang' => 'en']);
        $other = User::factory()->create();
        $active = Vehicle::query()->create([
            'user_id' => $owner->id,
            'license_plate' => 'KO792',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        Vehicle::query()->create([
            'user_id' => $other->id,
            'license_plate' => 'KO792',
            'vehicle_type_id' => $b->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);
        Storage::fake('local');

        $html = $this->actingAs($owner)->withSession(['locale' => 'en'])
            ->get(route('panel.vehicles', [], false))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('A category correction cannot be requested right now.', $html);
        $this->assertStringNotContainsString('name="documents[]"', $html);

        $this->post(route('panel.vehicles.category_change_requests.store', [], false), [
            'old_vehicle_id' => $active->id,
            'license_plate' => 'KO792',
            'old_vehicle_type_id' => $a->id,
            'requested_vehicle_type_id' => $b->id,
            'documents' => [UploadedFile::fake()->create('licence.pdf', 20, 'application/pdf')],
        ])->assertSessionHas('error', 'This registration plate already has conflicting vehicle categories. A new vehicle with this plate cannot be added right now. Write to bus@kotor.me.');

        $this->assertSame(0, VehicleCategoryChangeRequest::query()->count());
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $active->refresh()->status);
        $this->assertSame((int) $a->id, (int) $active->vehicle_type_id);
    }

    public function test_category_request_mail_is_sent_only_after_the_plate_lock_is_released(): void
    {
        [$a, $b] = $this->seedTypes();
        $user = User::factory()->create(['lang' => 'cg', 'email' => 'agency@example.com', 'name' => 'Agencija X']);
        $old = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO111',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);
        Storage::fake('local');

        $lockWasFree = null;
        Event::listen(MessageSending::class, function () use (&$lockWasFree): void {
            $probe = Cache::lock(VehiclePlateLock::lockName('KO111'), 5);
            $lockWasFree = $probe->get();
            if ($lockWasFree) {
                $probe->release();
            }
        });

        $this->actingAs($user)->post(route('panel.vehicles.category_change_requests.store', [], false), [
            'old_vehicle_id' => $old->id,
            'license_plate' => 'KO111',
            'old_vehicle_type_id' => $a->id,
            'requested_vehicle_type_id' => $b->id,
            'documents' => [UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf')],
        ])->assertRedirect(route('panel.vehicles', [], false));

        $this->assertTrue($lockWasFree);
        $this->assertSame(1, VehicleCategoryChangeRequest::query()->count());
    }

    public function test_plate_lock_blocks_the_write_until_it_is_released_and_rejects_nesting(): void
    {
        [$a] = $this->seedTypes();
        $user = User::factory()->create();
        $held = Cache::lock(VehiclePlateLock::lockName('KOLOCK'), 30);
        $this->assertTrue($held->get());

        $locks = new VehiclePlateLock();
        try {
            $locks->run('KOLOCK', function () use ($user, $a): void {
                Vehicle::query()->create([
                    'user_id' => $user->id,
                    'license_plate' => 'KOLOCK',
                    'vehicle_type_id' => $a->id,
                    'status' => Vehicle::STATUS_ACTIVE,
                ]);
            }, 0);
            $this->fail('The plate lock should have stopped the write.');
        } catch (VehiclePlateLockNotAcquired) {
            $this->assertFalse(Vehicle::query()->where('license_plate', 'KOLOCK')->exists());
        } finally {
            $held->release();
        }

        $locks->run('KOLOCK', function () use ($user, $a): void {
            Vehicle::query()->create([
                'user_id' => $user->id,
                'license_plate' => 'KOLOCK',
                'vehicle_type_id' => $a->id,
                'status' => Vehicle::STATUS_ACTIVE,
            ]);
        }, 0);
        $this->assertTrue(Vehicle::query()->where('license_plate', 'KOLOCK')->exists());

        try {
            $locks->run('KO777', function () use ($locks): void {
                $locks->run('KO778', function (): void {
                }, 0);
            }, 0);
            $this->fail('Nested plate locks should be rejected.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Nested', $e->getMessage());
        }

        $locks->run('KO779', function (): void {
        }, 0);
    }

    /**
     * @return array{0: VehicleType, 1: VehicleType}
     */
    private function seedTypes(): array
    {
        $a = VehicleType::query()->create(['price' => 10]);
        $b = VehicleType::query()->create(['price' => 20]);

        return [$a, $b];
    }

    private function reservationFor(User $user, string $plate, int $typeId, ?int $vehicleId = null): Reservation
    {
        return Reservation::query()->create([
            'user_id' => $user->id,
            'vehicle_id' => $vehicleId,
            'merchant_transaction_id' => 'mt-'.$plate.'-'.$user->id,
            'reservation_date' => now()->addDays(2)->toDateString(),
            'user_name' => 'Agency',
            'country' => 'ME',
            'license_plate' => $plate,
            'vehicle_type_id' => $typeId,
            'email' => $user->email,
            'preferred_locale' => 'en',
            'status' => 'paid',
            'invoice_amount' => '10.00',
        ]);
    }
}
