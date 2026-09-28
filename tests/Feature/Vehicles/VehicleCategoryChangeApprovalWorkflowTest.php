<?php

namespace Tests\Feature\Vehicles;

use App\Mail\VehicleCategoryChangeRequestMail;
use App\Models\Admin;
use App\Models\AdminAlert;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategoryChangeRequest;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class VehicleCategoryChangeApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function seedTypes(): array
    {
        $a = VehicleType::query()->create(['price' => 10]);
        $b = VehicleType::query()->create(['price' => 20]);

        foreach ([$a, $b] as $t) {
            VehicleTypeTranslation::query()->create(['vehicle_type_id' => $t->id, 'locale' => 'en', 'name' => 'T'.$t->id, 'description' => null]);
            VehicleTypeTranslation::query()->create(['vehicle_type_id' => $t->id, 'locale' => 'cg', 'name' => 'T'.$t->id, 'description' => null]);
        }

        return [$a, $b];
    }

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'vehadmin',
            'email' => 'veh-admin@example.com',
            'password' => bcrypt('secret-password-veh'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    public function test_vehicle_with_reservation_is_soft_removed_and_not_listed_for_agency(): void
    {
        [$t] = $this->seedTypes();
        $user = User::factory()->create();
        $this->actingAs($user);

        $slot = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $v = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO111',
            'vehicle_type_id' => $t->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);

        Reservation::query()->create([
            'user_id' => $user->id,
            'vehicle_id' => $v->id,
            'merchant_transaction_id' => 'mt-res-1',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            // Past reservation => not upcoming => destroy should soft-remove immediately.
            'reservation_date' => Carbon::now()->subDays(2)->toDateString(),
            'user_name' => 'u',
            'country' => 'ME',
            'license_plate' => $v->license_plate,
            'vehicle_type_id' => $v->vehicle_type_id,
            'email' => 'u@example.com',
            'preferred_locale' => 'en',
            'status' => 'paid',
            'invoice_amount' => 1,
        ]);

        $this->delete(route('panel.vehicles.destroy', $v->id, false))
            ->assertRedirect(route('panel.vehicles', [], false));

        $v->refresh();
        $this->assertSame(Vehicle::STATUS_REMOVED, (string) $v->status);

        $html = $this->get(route('panel.vehicles', [], false))->assertOk()->getContent();
        $this->assertStringNotContainsString('KO111', $html);
    }

    public function test_add_same_plate_same_category_reactivates_removed_vehicle(): void
    {
        [$a] = $this->seedTypes();
        $user = User::factory()->create(['lang' => 'en']);
        $this->actingAs($user);

        $v = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO111',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);

        $this->post(route('panel.vehicles.store', [], false), [
            'license_plate' => 'ko 111',
            'vehicle_type_id' => $a->id,
        ])->assertRedirect(route('panel.vehicles', [], false));

        $v->refresh();
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $v->status);
    }

    public function test_add_same_plate_different_category_is_blocked_and_prompts_document_request(): void
    {
        [$a, $b] = $this->seedTypes();
        $user = User::factory()->create(['lang' => 'cg']);
        $this->actingAs($user);

        Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO111',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);

        $resp = $this->post(route('panel.vehicles.store', [], false), [
            'license_plate' => 'KO111',
            'vehicle_type_id' => $b->id,
        ]);

        $resp->assertRedirect(route('panel.vehicles', [], false));

        $this->assertNotNull(session('category_change_needed'));
    }

    public function test_add_same_plate_different_category_shows_english_message_when_session_locale_is_en(): void
    {
        [$a, $b] = $this->seedTypes();
        $user = User::factory()->create(['lang' => 'cg']);
        $this->actingAs($user)->withSession(['locale' => 'en']);

        Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO111',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);

        $response = $this->post(route('panel.vehicles.store', [], false), [
            'license_plate' => 'KO111',
            'vehicle_type_id' => $b->id,
        ]);

        $response->assertRedirect(route('panel.vehicles', [], false));
        $response->assertSessionHas('error');

        $error = (string) session('error');
        $this->assertMatchesRegularExpression('/different category|category change request/i', $error);
        $this->assertStringNotContainsString('Ova tablica već postoji', $error);
    }

    public function test_add_same_plate_different_category_shows_cg_message_when_session_locale_is_cg(): void
    {
        [$a, $b] = $this->seedTypes();
        $user = User::factory()->create(['lang' => 'en']);
        $this->actingAs($user)->withSession(['locale' => 'cg']);

        Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO111',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);

        $response = $this->post(route('panel.vehicles.store', [], false), [
            'license_plate' => 'KO111',
            'vehicle_type_id' => $b->id,
        ]);

        $response->assertRedirect(route('panel.vehicles', [], false));
        $response->assertSessionHas('error');

        $error = (string) session('error');
        $this->assertMatchesRegularExpression('/kategorij/i', $error);
        $this->assertStringNotContainsString('different category', $error);
        $this->assertStringNotContainsString('Please submit a category change request', $error);
    }

    public function test_request_stores_private_document_sends_mail_and_creates_warning_and_dedupes_pending(): void
    {
        [$a, $b] = $this->seedTypes();
        $user = User::factory()->create(['lang' => 'cg', 'email' => 'agency@example.com', 'name' => 'Agencija X']);
        $this->actingAs($user);

        Storage::fake('local');
        Mail::fake();

        $old = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO111',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);

        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $this->post(route('panel.vehicles.category_change_requests.store', [], false), [
            'old_vehicle_id' => $old->id,
            'license_plate' => 'KO111',
            'old_vehicle_type_id' => $a->id,
            'requested_vehicle_type_id' => $b->id,
            'documents' => [$file],
        ])->assertRedirect(route('panel.vehicles', [], false));

        $req = VehicleCategoryChangeRequest::query()->firstOrFail();
        $this->assertSame(VehicleCategoryChangeRequest::STATUS_PENDING, (string) $req->status);
        $this->assertSame(1, $req->attachments()->count());
        Storage::disk('local')->assertExists($req->attachments()->first()->path);
        Storage::disk('local')->assertExists($req->document_path);

        Mail::assertSent(VehicleCategoryChangeRequestMail::class, 1);

        $alert = AdminAlert::query()->where('type', 'vehicle_category_change_request')->firstOrFail();
        $this->assertNull($alert->removed_at);
        $this->assertSame((int) $req->id, (int) ($alert->payload_json['vehicle_category_change_request_id'] ?? 0));

        // Duplicate pending must not create another request.
        $this->post(route('panel.vehicles.category_change_requests.store', [], false), [
            'old_vehicle_id' => $old->id,
            'license_plate' => 'KO111',
            'old_vehicle_type_id' => $a->id,
            'requested_vehicle_type_id' => $b->id,
            'documents' => [$file],
        ])->assertRedirect(route('panel.vehicles', [], false));

        $this->assertSame(1, VehicleCategoryChangeRequest::query()->count());
    }

    public function test_admin_preview_is_admin_only_and_approve_and_reject_workflows_update_status_and_remove_warning(): void
    {
        [$a, $b] = $this->seedTypes();
        $user = User::factory()->create(['lang' => 'cg', 'email' => 'agency@example.com', 'name' => 'Agencija X']);

        Storage::fake('local');

        $old = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO111',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);

        $req = VehicleCategoryChangeRequest::query()->create([
            'user_id' => $user->id,
            'old_vehicle_id' => $old->id,
            'license_plate' => 'KO111',
            'old_vehicle_type_id' => $a->id,
            'requested_vehicle_type_id' => $b->id,
            'status' => VehicleCategoryChangeRequest::STATUS_PENDING,
            'document_original_name' => 'doc.pdf',
            'document_path' => 'vehicle-category-change-requests/1/document',
            'document_mime_type' => 'application/pdf',
            'document_size_bytes' => 100,
            'locale' => 'cg',
        ]);

        Storage::disk('local')->put($req->document_path, 'PDF');

        AdminAlert::query()->create([
            'type' => 'vehicle_category_change_request',
            'status' => AdminAlert::STATUS_UNREAD,
            'title' => 't',
            'message' => 'm',
            'payload_json' => [
                'vehicle_category_change_request_id' => (int) $req->id,
                'user_id' => (int) $user->id,
                'license_plate' => 'KO111',
            ],
        ]);

        // Non-admin cannot preview (redirect to admin login).
        $this->actingAs($user);
        $this->get(route('panel_admin.agencies.vehicle_category_change_requests.document', ['user' => $user->id, 'request' => $req->id], false))
            ->assertStatus(302);

        // Approve as admin.
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', ['user' => $user->id, 'request' => $req->id], false))
            ->assertRedirect(route('panel_admin.agencies.show', $user, false));

        $req->refresh();
        $old->refresh();
        $this->assertSame(VehicleCategoryChangeRequest::STATUS_APPROVED, (string) $req->status);
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $old->status);
        $this->assertSame($b->id, (int) $old->vehicle_type_id);

        $alert = AdminAlert::query()->where('type', 'vehicle_category_change_request')->firstOrFail();
        $this->assertNotNull($alert->removed_at);

        // New request to reject.
        $old->update(['status' => Vehicle::STATUS_REMOVED, 'vehicle_type_id' => $a->id]);
        $req2 = VehicleCategoryChangeRequest::query()->create([
            'user_id' => $user->id,
            'old_vehicle_id' => $old->id,
            'license_plate' => 'KO111',
            'old_vehicle_type_id' => $a->id,
            'requested_vehicle_type_id' => $b->id,
            'status' => VehicleCategoryChangeRequest::STATUS_PENDING,
            'document_original_name' => 'doc2.pdf',
            'document_path' => 'vehicle-category-change-requests/2/document',
            'document_mime_type' => 'application/pdf',
            'document_size_bytes' => 100,
            'locale' => 'cg',
        ]);
        Storage::disk('local')->put($req2->document_path, 'PDF');
        AdminAlert::query()->create([
            'type' => 'vehicle_category_change_request',
            'status' => AdminAlert::STATUS_UNREAD,
            'title' => 't',
            'message' => 'm',
            'payload_json' => [
                'vehicle_category_change_request_id' => (int) $req2->id,
                'user_id' => (int) $user->id,
                'license_plate' => 'KO111',
            ],
        ]);

        $this->post(route('panel_admin.agencies.vehicle_category_change_requests.reject', ['user' => $user->id, 'request' => $req2->id], false), [
            'reason' => 'Dokument nije validan.',
        ])
            ->assertRedirect(route('panel_admin.agencies.show', $user, false));

        $req2->refresh();
        $old->refresh();
        $this->assertSame(VehicleCategoryChangeRequest::STATUS_REJECTED, (string) $req2->status);
        $this->assertSame(Vehicle::STATUS_REMOVED, (string) $old->status);

        $alert2 = AdminAlert::query()
            ->where('type', 'vehicle_category_change_request')
            ->where('payload_json->vehicle_category_change_request_id', (int) $req2->id)
            ->firstOrFail();
        $this->assertNotNull($alert2->removed_at);
    }

    /** @return array{0: VehicleType, 1: VehicleType, 2: VehicleType} */
    private function seedThreeTypes(): array
    {
        $a = VehicleType::query()->create(['price' => 10]);
        $b = VehicleType::query()->create(['price' => 20]);
        $c = VehicleType::query()->create(['price' => 30]);

        foreach ([$a, $b, $c] as $t) {
            VehicleTypeTranslation::query()->create(['vehicle_type_id' => $t->id, 'locale' => 'en', 'name' => 'T'.$t->id, 'description' => null]);
            VehicleTypeTranslation::query()->create(['vehicle_type_id' => $t->id, 'locale' => 'cg', 'name' => 'T'.$t->id, 'description' => null]);
        }

        return [$a, $b, $c];
    }

    /**
     * @param  array{user: User, old: Vehicle, a: VehicleType, b: VehicleType}  $fixtures
     */
    private function createPendingCategoryChangeRequest(array $fixtures): VehicleCategoryChangeRequest
    {
        return VehicleCategoryChangeRequest::query()->create([
            'user_id' => $fixtures['user']->id,
            'old_vehicle_id' => $fixtures['old']->id,
            'license_plate' => (string) $fixtures['old']->license_plate,
            'old_vehicle_type_id' => $fixtures['a']->id,
            'requested_vehicle_type_id' => $fixtures['b']->id,
            'status' => VehicleCategoryChangeRequest::STATUS_PENDING,
            'document_original_name' => 'doc.pdf',
            'document_path' => 'vehicle-category-change-requests/test/document',
            'document_mime_type' => 'application/pdf',
            'document_size_bytes' => 100,
            'locale' => 'cg',
        ]);
    }

    public function test_approve_from_removed_old_category_sets_active_requested_category(): void
    {
        [$a, $b] = $this->seedTypes();
        $user = User::factory()->create();
        $old = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO222',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);
        $req = $this->createPendingCategoryChangeRequest([
            'user' => $user,
            'old' => $old,
            'a' => $a,
            'b' => $b,
        ]);
        $admin = $this->seedAdmin();

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', [
                'user' => $user->id,
                'request' => $req->id,
            ], false))
            ->assertRedirect(route('panel_admin.agencies.show', $user, false));

        $req->refresh();
        $old->refresh();
        $this->assertSame(VehicleCategoryChangeRequest::STATUS_APPROVED, (string) $req->status);
        $this->assertNotNull($req->reviewed_at);
        $this->assertSame((int) $admin->id, (int) $req->reviewed_by_admin_id);
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $old->status);
        $this->assertSame((int) $b->id, (int) $old->vehicle_type_id);
    }

    public function test_approve_from_active_old_category_sets_requested_category(): void
    {
        [$a, $b] = $this->seedTypes();
        $user = User::factory()->create();
        $old = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO333',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $req = $this->createPendingCategoryChangeRequest([
            'user' => $user,
            'old' => $old,
            'a' => $a,
            'b' => $b,
        ]);

        AdminAlert::query()->create([
            'type' => 'vehicle_category_change_request',
            'status' => AdminAlert::STATUS_UNREAD,
            'title' => 't',
            'message' => 'm',
            'payload_json' => [
                'vehicle_category_change_request_id' => (int) $req->id,
                'user_id' => (int) $user->id,
                'license_plate' => 'KO333',
            ],
        ]);

        $admin = $this->seedAdmin();

        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Mail::fake();

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', [
                'user' => $user->id,
                'request' => $req->id,
            ], false))
            ->assertRedirect(route('panel_admin.agencies.show', $user, false));

        $req->refresh();
        $old->refresh();
        $this->assertSame(VehicleCategoryChangeRequest::STATUS_APPROVED, (string) $req->status);
        $this->assertNotNull($req->reviewed_at);
        $this->assertSame((int) $admin->id, (int) $req->reviewed_by_admin_id);
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $old->status);
        $this->assertSame((int) $b->id, (int) $old->vehicle_type_id);

        $alert = AdminAlert::query()
            ->where('type', 'vehicle_category_change_request')
            ->where('payload_json->vehicle_category_change_request_id', (int) $req->id)
            ->firstOrFail();
        $this->assertNotNull($alert->removed_at);

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ArchiveVehicleCategoryChangeRequestAttachmentsJob::class);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\VehicleCategoryChangeApprovedMail::class);
    }

    public function test_approve_rejects_active_third_category(): void
    {
        [$a, $b, $c] = $this->seedThreeTypes();
        $user = User::factory()->create();
        $old = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO444',
            'vehicle_type_id' => $c->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $req = $this->createPendingCategoryChangeRequest([
            'user' => $user,
            'old' => $old,
            'a' => $a,
            'b' => $b,
        ]);
        $admin = $this->seedAdmin();

        $this->actingAs($admin, 'panel_admin')
            ->from(route('panel_admin.agencies.vehicle_category_change_requests.show', [
                'user' => $user->id,
                'request' => $req->id,
            ], false))
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', [
                'user' => $user->id,
                'request' => $req->id,
            ], false))
            ->assertRedirect(route('panel_admin.agencies.vehicle_category_change_requests.show', [
                'user' => $user->id,
                'request' => $req->id,
            ], false))
            ->assertSessionHasErrors('vehicle');

        $req->refresh();
        $old->refresh();
        $this->assertSame(VehicleCategoryChangeRequest::STATUS_PENDING, (string) $req->status);
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $old->status);
        $this->assertSame((int) $c->id, (int) $old->vehicle_type_id);
    }

    public function test_approve_rejects_already_requested_category(): void
    {
        [$a, $b] = $this->seedTypes();
        $user = User::factory()->create();
        $old = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO555',
            'vehicle_type_id' => $b->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $req = $this->createPendingCategoryChangeRequest([
            'user' => $user,
            'old' => $old,
            'a' => $a,
            'b' => $b,
        ]);
        $admin = $this->seedAdmin();

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', [
                'user' => $user->id,
                'request' => $req->id,
            ], false))
            ->assertSessionHasErrors('vehicle');

        $req->refresh();
        $old->refresh();
        $this->assertSame(VehicleCategoryChangeRequest::STATUS_PENDING, (string) $req->status);
        $this->assertSame((int) $b->id, (int) $old->vehicle_type_id);
    }

    public function test_approve_rejects_removed_but_category_drifted(): void
    {
        [$a, $b, $c] = $this->seedThreeTypes();
        $user = User::factory()->create();
        $old = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO666',
            'vehicle_type_id' => $c->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);
        $req = $this->createPendingCategoryChangeRequest([
            'user' => $user,
            'old' => $old,
            'a' => $a,
            'b' => $b,
        ]);
        $admin = $this->seedAdmin();

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', [
                'user' => $user->id,
                'request' => $req->id,
            ], false))
            ->assertSessionHasErrors('vehicle');

        $req->refresh();
        $old->refresh();
        $this->assertSame(VehicleCategoryChangeRequest::STATUS_PENDING, (string) $req->status);
        $this->assertSame(Vehicle::STATUS_REMOVED, (string) $old->status);
        $this->assertSame((int) $c->id, (int) $old->vehicle_type_id);
    }

    public function test_same_category_reactivation_blocked_while_category_change_pending(): void
    {
        [$a, $b] = $this->seedTypes();
        $user = User::factory()->create(['lang' => 'en']);
        $this->actingAs($user);

        $old = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO777',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);
        $this->createPendingCategoryChangeRequest([
            'user' => $user,
            'old' => $old,
            'a' => $a,
            'b' => $b,
        ]);

        $this->post(route('panel.vehicles.store', [], false), [
            'license_plate' => 'KO777',
            'vehicle_type_id' => $a->id,
        ])
            ->assertRedirect(route('panel.vehicles', [], false))
            ->assertSessionHas('error');

        $old->refresh();
        $this->assertSame(Vehicle::STATUS_REMOVED, (string) $old->status);
        $this->assertSame(1, VehicleCategoryChangeRequest::query()->where('status', VehicleCategoryChangeRequest::STATUS_PENDING)->count());
        $this->assertMatchesRegularExpression('/awaiting admin|category change request/i', (string) session('error'));
    }

    public function test_failed_approve_validation_errors_visible_on_request_detail_page(): void
    {
        [$a, $b, $c] = $this->seedThreeTypes();
        $user = User::factory()->create();
        $old = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO888',
            'vehicle_type_id' => $c->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $req = $this->createPendingCategoryChangeRequest([
            'user' => $user,
            'old' => $old,
            'a' => $a,
            'b' => $b,
        ]);
        $admin = $this->seedAdmin();
        $detailUrl = route('panel_admin.agencies.vehicle_category_change_requests.show', [
            'user' => $user->id,
            'request' => $req->id,
        ], false);

        $html = $this->actingAs($admin, 'panel_admin')
            ->from($detailUrl)
            ->followingRedirects()
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', [
                'user' => $user->id,
                'request' => $req->id,
            ], false))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Trenutna kategorija vozila ne odgovara staroj kategoriji zahtjeva.', $html);
    }

    public function test_case_b_approve_does_not_rewrite_existing_reservation_snapshot(): void
    {
        [$a, $b] = $this->seedTypes();
        $user = User::factory()->create();
        $old = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO999',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $slot = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $reservation = Reservation::query()->create([
            'user_id' => $user->id,
            'vehicle_id' => $old->id,
            'merchant_transaction_id' => 'mt-cat-b-safe',
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => Carbon::now()->addDays(3)->toDateString(),
            'user_name' => 'u',
            'country' => 'ME',
            'license_plate' => $old->license_plate,
            'vehicle_type_id' => $a->id,
            'email' => 'u@example.com',
            'preferred_locale' => 'en',
            'status' => 'paid',
            'invoice_amount' => '10.00',
        ]);
        $req = $this->createPendingCategoryChangeRequest([
            'user' => $user,
            'old' => $old,
            'a' => $a,
            'b' => $b,
        ]);
        $admin = $this->seedAdmin();

        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Mail::fake();

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', [
                'user' => $user->id,
                'request' => $req->id,
            ], false))
            ->assertRedirect();

        $reservation->refresh();
        $old->refresh();
        $this->assertSame((int) $a->id, (int) $reservation->vehicle_type_id);
        $this->assertSame('10.00', (string) $reservation->invoice_amount);
        $this->assertSame((int) $b->id, (int) $old->vehicle_type_id);
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $old->status);
    }
}

