<?php

namespace Tests\Feature\AdminPanel;

use App\Models\Admin;
use App\Models\AdminAlert;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategoryChangeRequest;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class VehicleCategoryChangeAlertNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'catnav',
            'email' => 'catnav@example.com',
            'password' => bcrypt('secret-password'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    /** @return array{user: User, request: VehicleCategoryChangeRequest} */
    private function seedPendingRequest(): array
    {
        $typeA = VehicleType::query()->create(['price' => 15.00]);
        $typeB = VehicleType::query()->create(['price' => 40.00]);
        foreach ([$typeA, $typeB] as $type) {
            VehicleTypeTranslation::query()->create([
                'vehicle_type_id' => $type->id,
                'locale' => 'cg',
                'name' => 'Type '.$type->id,
                'description' => null,
            ]);
        }

        $user = User::factory()->create(['name' => 'Agencija Nav', 'email' => 'nav@example.com']);
        $vehicle = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'PGNAV1',
            'vehicle_type_id' => $typeA->id,
            'status' => Vehicle::STATUS_REMOVED,
        ]);

        Storage::fake('local');

        $req = VehicleCategoryChangeRequest::query()->create([
            'user_id' => $user->id,
            'old_vehicle_id' => $vehicle->id,
            'license_plate' => 'PGNAV1',
            'old_vehicle_type_id' => $typeA->id,
            'requested_vehicle_type_id' => $typeB->id,
            'status' => VehicleCategoryChangeRequest::STATUS_PENDING,
            'document_original_name' => 'doc.pdf',
            'document_path' => 'vehicle-category-change-requests/99/document',
            'document_mime_type' => 'application/pdf',
            'document_size_bytes' => 100,
            'locale' => 'cg',
        ]);
        Storage::disk('local')->put($req->document_path, 'PDF');

        AdminAlert::query()->create([
            'type' => 'vehicle_category_change_request',
            'status' => AdminAlert::STATUS_UNREAD,
            'title' => 'Zahtjev za promjenu kategorije vozila',
            'message' => 'Došao je zahtjev.',
            'payload_json' => [
                'vehicle_category_change_request_id' => (int) $req->id,
                'user_id' => (int) $user->id,
                'license_plate' => 'PGNAV1',
            ],
        ]);

        return ['user' => $user, 'request' => $req];
    }

    public function test_warnings_dashboard_links_category_change_alert_to_review_page(): void
    {
        $fixtures = $this->seedPendingRequest();
        $showUrl = route('panel_admin.agencies.vehicle_category_change_requests.show', [
            'user' => $fixtures['user']->id,
            'request' => $fixtures['request']->id,
        ], false);

        $this->actingAs($this->seedAdmin(), 'panel_admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Otvori zahtjev', false)
            ->assertSee($showUrl, false);
    }

    public function test_pending_request_review_page_shows_approve_and_reject(): void
    {
        $fixtures = $this->seedPendingRequest();

        $this->actingAs($this->seedAdmin(), 'panel_admin')
            ->get(route('panel_admin.agencies.vehicle_category_change_requests.show', [
                'user' => $fixtures['user']->id,
                'request' => $fixtures['request']->id,
            ], false))
            ->assertOk()
            ->assertSee('PGNAV1', false)
            ->assertSee('Na čekanju', false)
            ->assertSee('Prihvati', false)
            ->assertSee('Odbij', false);
    }

    public function test_processed_request_review_page_is_read_only(): void
    {
        $fixtures = $this->seedPendingRequest();
        $fixtures['request']->update([
            'status' => VehicleCategoryChangeRequest::STATUS_APPROVED,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($this->seedAdmin(), 'panel_admin')
            ->get(route('panel_admin.agencies.vehicle_category_change_requests.show', [
                'user' => $fixtures['user']->id,
                'request' => $fixtures['request']->id,
            ], false))
            ->assertOk()
            ->assertSee('Prihvaćen', false)
            ->assertSee('Prikaz je samo za pregled', false)
            ->assertDontSee('>Prihvati<', false)
            ->assertDontSee('>Odbij<', false);
    }
}
