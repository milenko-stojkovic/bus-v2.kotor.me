<?php

namespace Tests\Feature\Vehicles;

use App\Mail\VehicleCategoryChangeApprovedMail;
use App\Mail\VehicleCategoryChangeRejectedMail;
use App\Models\Admin;
use App\Models\AdminAlert;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategoryChangeRequest;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\VehicleCategoryChange\VehicleCategoryChangeDecisionNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class VehicleCategoryChangeDecisionNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{a: VehicleType, b: VehicleType, user: User, old: Vehicle} */
    private function seedFixtures(string $lang = 'cg'): array
    {
        $a = VehicleType::query()->create(['price' => 10]);
        $b = VehicleType::query()->create(['price' => 20]);
        foreach ([$a, $b] as $t) {
            VehicleTypeTranslation::query()->create(['vehicle_type_id' => $t->id, 'locale' => 'en', 'name' => 'Type '.$t->id, 'description' => null]);
            VehicleTypeTranslation::query()->create(['vehicle_type_id' => $t->id, 'locale' => 'cg', 'name' => 'Tip '.$t->id, 'description' => null]);
        }

        $user = User::factory()->create(['lang' => $lang, 'email' => 'notify@example.com', 'name' => 'Notify Agency']);
        $old = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'KO444',
            'vehicle_type_id' => $a->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);

        return compact('a', 'b', 'user', 'old');
    }

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'notifyadmin',
            'email' => 'notify-admin@example.com',
            'password' => bcrypt('secret-password-notify'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    private function createPendingRequest(array $fixtures): VehicleCategoryChangeRequest
    {
        return VehicleCategoryChangeRequest::query()->create([
            'user_id' => $fixtures['user']->id,
            'old_vehicle_id' => $fixtures['old']->id,
            'license_plate' => 'KO444',
            'old_vehicle_type_id' => $fixtures['a']->id,
            'requested_vehicle_type_id' => $fixtures['b']->id,
            'status' => VehicleCategoryChangeRequest::STATUS_PENDING,
            'document_original_name' => 'doc.pdf',
            'document_path' => 'vehicle-category-change-requests/pending/doc.pdf',
            'document_mime_type' => 'application/pdf',
            'document_size_bytes' => 100,
            'locale' => 'cg',
        ]);
    }

    public function test_approval_sends_email_to_agency(): void
    {
        Mail::fake();
        Queue::fake();
        $fixtures = $this->seedFixtures();
        $req = $this->createPendingRequest($fixtures);
        $this->seedAlert($req, $fixtures['user']);
        $admin = $this->seedAdmin();

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', [
                'user' => $fixtures['user']->id,
                'request' => $req->id,
            ], false))
            ->assertRedirect(route('panel_admin.agencies.show', $fixtures['user'], false));

        Mail::assertSent(VehicleCategoryChangeApprovedMail::class, function (VehicleCategoryChangeApprovedMail $mail) use ($fixtures): bool {
            return $mail->hasTo('notify@example.com')
                && $mail->licensePlate === 'KO444'
                && str_contains($mail->newCategory, 'Tip 2');
        });

        $req->refresh();
        $this->assertNotNull($req->approved_notification_sent_at);
    }

    public function test_rejection_sends_email_to_agency_with_reason(): void
    {
        Mail::fake();
        Queue::fake();
        $fixtures = $this->seedFixtures();
        $req = $this->createPendingRequest($fixtures);
        $this->seedAlert($req, $fixtures['user']);
        $admin = $this->seedAdmin();

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.reject', [
                'user' => $fixtures['user']->id,
                'request' => $req->id,
            ], false), [
                'reason' => 'Dokumentacija nije dovoljno jasna.',
            ])
            ->assertRedirect(route('panel_admin.agencies.show', $fixtures['user'], false));

        Mail::assertSent(VehicleCategoryChangeRejectedMail::class, function (VehicleCategoryChangeRejectedMail $mail): bool {
            return $mail->hasTo('notify@example.com')
                && $mail->licensePlate === 'KO444'
                && $mail->rejectionReason === 'Dokumentacija nije dovoljno jasna.';
        });

        $req->refresh();
        $this->assertSame('Dokumentacija nije dovoljno jasna.', $req->rejection_reason);
        $this->assertNotNull($req->rejected_notification_sent_at);
    }

    public function test_email_includes_plate_and_requested_category(): void
    {
        Mail::fake();
        Queue::fake();
        $fixtures = $this->seedFixtures('en');
        $req = $this->createPendingRequest($fixtures);
        $this->seedAlert($req, $fixtures['user']);
        $admin = $this->seedAdmin();

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', [
                'user' => $fixtures['user']->id,
                'request' => $req->id,
            ], false));

        Mail::assertSent(VehicleCategoryChangeApprovedMail::class, function (VehicleCategoryChangeApprovedMail $mail): bool {
            return $mail->agencyLocale === 'en'
                && $mail->licensePlate === 'KO444'
                && str_contains($mail->newCategory, 'Type 2');
        });
    }

    public function test_email_is_not_sent_when_transaction_does_not_commit(): void
    {
        Mail::fake();
        $fixtures = $this->seedFixtures();
        $req = $this->createPendingRequest($fixtures);
        $req->update(['status' => VehicleCategoryChangeRequest::STATUS_APPROVED]);
        $admin = $this->seedAdmin();

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', [
                'user' => $fixtures['user']->id,
                'request' => $req->id,
            ], false))
            ->assertSessionHasErrors('status');

        Mail::assertNothingSent();
    }

    public function test_duplicate_notification_is_not_sent_when_already_marked(): void
    {
        Mail::fake();
        $fixtures = $this->seedFixtures();
        $req = VehicleCategoryChangeRequest::query()->create([
            'user_id' => $fixtures['user']->id,
            'old_vehicle_id' => $fixtures['old']->id,
            'license_plate' => 'KO444',
            'old_vehicle_type_id' => $fixtures['a']->id,
            'requested_vehicle_type_id' => $fixtures['b']->id,
            'status' => VehicleCategoryChangeRequest::STATUS_APPROVED,
            'document_original_name' => 'doc.pdf',
            'document_path' => 'vehicle-category-change-requests/x/doc.pdf',
            'document_mime_type' => 'application/pdf',
            'document_size_bytes' => 100,
            'locale' => 'cg',
            'reviewed_at' => now(),
            'approved_notification_sent_at' => now(),
        ]);

        app(VehicleCategoryChangeDecisionNotificationService::class)->notifyApproved((int) $req->id);

        Mail::assertNothingSent();
    }

    public function test_second_approve_attempt_does_not_send_duplicate_email(): void
    {
        Mail::fake();
        Queue::fake();
        $fixtures = $this->seedFixtures();
        $req = $this->createPendingRequest($fixtures);
        $this->seedAlert($req, $fixtures['user']);
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $route = route('panel_admin.agencies.vehicle_category_change_requests.approve', [
            'user' => $fixtures['user']->id,
            'request' => $req->id,
        ], false);

        $this->post($route)->assertRedirect();
        $this->post($route)->assertSessionHasErrors('status');

        Mail::assertSent(VehicleCategoryChangeApprovedMail::class, 1);
    }

    public function test_approve_and_reject_workflows_remain_unchanged(): void
    {
        Mail::fake();
        Queue::fake();
        $fixtures = $this->seedFixtures();
        $req = $this->createPendingRequest($fixtures);
        $this->seedAlert($req, $fixtures['user']);
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');

        $this->post(route('panel_admin.agencies.vehicle_category_change_requests.approve', [
            'user' => $fixtures['user']->id,
            'request' => $req->id,
        ], false));

        $req->refresh();
        $fixtures['old']->refresh();
        $this->assertSame(VehicleCategoryChangeRequest::STATUS_APPROVED, (string) $req->status);
        $this->assertSame(Vehicle::STATUS_ACTIVE, (string) $fixtures['old']->status);
        $this->assertSame($fixtures['b']->id, (int) $fixtures['old']->vehicle_type_id);

        $fixtures['old']->update(['status' => Vehicle::STATUS_REMOVED, 'vehicle_type_id' => $fixtures['a']->id]);
        $req2 = $this->createPendingRequest($fixtures);
        $this->seedAlert($req2, $fixtures['user']);

        $this->post(route('panel_admin.agencies.vehicle_category_change_requests.reject', [
            'user' => $fixtures['user']->id,
            'request' => $req2->id,
        ], false), ['reason' => 'Nedovoljna dokumentacija.']);

        $req2->refresh();
        $fixtures['old']->refresh();
        $this->assertSame(VehicleCategoryChangeRequest::STATUS_REJECTED, (string) $req2->status);
        $this->assertSame(Vehicle::STATUS_REMOVED, (string) $fixtures['old']->status);
    }

    public function test_rejection_requires_reason(): void
    {
        $fixtures = $this->seedFixtures();
        $req = $this->createPendingRequest($fixtures);
        $admin = $this->seedAdmin();

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.reject', [
                'user' => $fixtures['user']->id,
                'request' => $req->id,
            ], false), [])
            ->assertSessionHasErrors('reason');
    }

    public function test_english_rejection_email_subject(): void
    {
        Mail::fake();
        Queue::fake();
        $fixtures = $this->seedFixtures('en');
        $req = $this->createPendingRequest($fixtures);
        $this->seedAlert($req, $fixtures['user']);
        $admin = $this->seedAdmin();

        $this->actingAs($admin, 'panel_admin')
            ->post(route('panel_admin.agencies.vehicle_category_change_requests.reject', [
                'user' => $fixtures['user']->id,
                'request' => $req->id,
            ], false), ['reason' => 'Insufficient documentation.']);

        Mail::assertSent(VehicleCategoryChangeRejectedMail::class, function (VehicleCategoryChangeRejectedMail $mail): bool {
            return $mail->agencyLocale === 'en';
        });
    }

    private function seedAlert(VehicleCategoryChangeRequest $req, User $user): void
    {
        AdminAlert::query()->create([
            'type' => 'vehicle_category_change_request',
            'status' => AdminAlert::STATUS_UNREAD,
            'title' => 't',
            'message' => 'm',
            'payload_json' => [
                'vehicle_category_change_request_id' => (int) $req->id,
                'user_id' => (int) $user->id,
                'license_plate' => 'KO444',
            ],
        ]);
    }
}
