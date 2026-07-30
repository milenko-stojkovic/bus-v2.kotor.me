<?php

namespace Tests\Feature\Payment;

use App\Jobs\PaymentCallbackJob;
use App\Jobs\ProcessReservationAfterPaymentJob;
use App\Models\AdminAlert;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\TempData;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\Payment\LateSuccessManualResolutionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class GuestLateSuccessManualResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function seedType(float $price = 50.0): VehicleType
    {
        $t = VehicleType::query()->create(['price' => $price]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $t->id,
            'locale' => 'en',
            'name' => 'Bus',
            'description' => null,
        ]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $t->id,
            'locale' => 'cg',
            'name' => 'Autobus',
            'description' => null,
        ]);

        return $t;
    }

    /**
     * @return array{0: ListOfTimeSlot, 1: ListOfTimeSlot}
     */
    private function seedSlots(): array
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '16:20 - 16:40']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '18:00 - 18:20']);

        return [$drop, $pick];
    }

    private function seedParking(string $date, int $dropId, int $pickId, int $capacity = 9, int $reserved = 0, int $pending = 0): void
    {
        foreach ([$dropId, $pickId] as $slotId) {
            DailyParkingData::query()->create([
                'date' => $date,
                'time_slot_id' => $slotId,
                'capacity' => $capacity,
                'reserved' => $reserved,
                'pending' => $pending,
                'is_blocked' => false,
            ]);
        }
    }

    private function actingStaffUser(): User
    {
        $user = User::factory()->create(['email' => 'late-staff@example.test']);
        $role = Role::query()->create(['name' => 'admin', 'guard_name' => 'web']);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_a_success_after_expiration_creates_late_success_for_guest(): void
    {
        Mail::fake();
        [$drop, $pick] = $this->seedSlots();
        $vt = $this->seedType();
        $date = Carbon::now()->addDays(3)->toDateString();

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-guest-late-a',
            'retry_token' => 'rt-a',
            'user_id' => null,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest Co',
            'country' => 'GR',
            'license_plate' => 'IYH1591',
            'vehicle_type_id' => $vt->id,
            'email' => 'guest-late@example.test',
            'preferred_locale' => 'en',
            'status' => TempData::STATUS_EXPIRED,
            'invoice_amount_snapshot' => '50.00',
        ]);

        (new PaymentCallbackJob(
            ['merchant_transaction_id' => 'mt-guest-late-a', 'status' => 'success'],
            ['result' => 'OK']
        ))->handle();

        $temp->refresh();
        $this->assertSame(TempData::STATUS_LATE_SUCCESS, (string) $temp->status);
        $this->assertSame(0, Reservation::query()->count());
    }

    public function test_b_and_c_late_success_exposes_force_and_reject_without_sql_status_change(): void
    {
        Mail::fake();
        [$drop, $pick] = $this->seedSlots();
        $vt = $this->seedType();
        $date = Carbon::now()->addDays(3)->toDateString();
        $this->seedParking($date, $drop->id, $pick->id);

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-guest-late-b',
            'retry_token' => 'rt-b',
            'user_id' => null,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest Co',
            'country' => 'GR',
            'license_plate' => 'IYH1591',
            'vehicle_type_id' => $vt->id,
            'email' => 'guest-late-b@example.test',
            'preferred_locale' => 'en',
            'status' => TempData::STATUS_LATE_SUCCESS,
        ]);

        $staff = $this->actingStaffUser();
        $html = $this->actingAs($staff)
            ->get(route('staff.late-success.show', ['id' => $temp->id], false))
            ->assertOk()
            ->assertSee('Prisilno kreiraj rezervaciju', false)
            ->assertSee('Odbij plaćanje', false)
            ->assertSee('The requested arrival/departure slots are still available.', false)
            ->getContent();

        $this->assertStringContainsString('staff/late-success/'.$temp->id.'/force', $html);
        $this->assertSame(TempData::STATUS_LATE_SUCCESS, (string) $temp->fresh()->status);
    }

    public function test_d_e_f_g_force_runs_success_pipeline_updates_parking_and_dispatches_jobs(): void
    {
        Bus::fake([ProcessReservationAfterPaymentJob::class]);
        Mail::fake();

        [$drop, $pick] = $this->seedSlots();
        $vt = $this->seedType();
        $date = Carbon::now()->addDays(3)->toDateString();
        $this->seedParking($date, $drop->id, $pick->id, capacity: 9, reserved: 2, pending: 0);

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-guest-late-force',
            'retry_token' => 'rt-force',
            'user_id' => null,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest Force',
            'country' => 'GR',
            'license_plate' => 'FORCE1',
            'vehicle_type_id' => $vt->id,
            'email' => 'force@example.test',
            'preferred_locale' => 'en',
            'status' => TempData::STATUS_LATE_SUCCESS,
            'invoice_amount_snapshot' => '50.00',
        ]);

        $staff = $this->actingStaffUser();
        $this->actingAs($staff)
            ->post(route('staff.late-success.force', ['id' => $temp->id], false))
            ->assertRedirect();

        $temp->refresh();
        $this->assertSame(TempData::STATUS_PROCESSED, (string) $temp->status);
        $this->assertSame('admin_forced', (string) $temp->resolution_reason);

        $reservation = Reservation::query()->where('merchant_transaction_id', 'mt-guest-late-force')->first();
        $this->assertNotNull($reservation);
        $this->assertSame('paid', (string) $reservation->status);
        $this->assertSame('FORCE1', (string) $reservation->license_plate);

        foreach ([$drop->id, $pick->id] as $slotId) {
            $row = DailyParkingData::query()
                ->whereDate('date', $date)
                ->where('time_slot_id', $slotId)
                ->firstOrFail();
            $this->assertSame(3, (int) $row->reserved);
            $this->assertSame(0, (int) $row->pending);
        }

        Bus::assertDispatched(ProcessReservationAfterPaymentJob::class, function (ProcessReservationAfterPaymentJob $job) use ($reservation): bool {
            return (int) $job->reservationId === (int) $reservation->id;
        });

        // Idempotent second Force
        $this->actingAs($staff)
            ->post(route('staff.late-success.force', ['id' => $temp->id], false))
            ->assertRedirect();
        $this->assertSame(1, Reservation::query()->where('merchant_transaction_id', 'mt-guest-late-force')->count());
    }

    public function test_h_reject_leaves_reservation_uncreated(): void
    {
        Mail::fake();
        [$drop, $pick] = $this->seedSlots();
        $vt = $this->seedType();
        $date = Carbon::now()->addDays(3)->toDateString();

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-guest-late-reject',
            'retry_token' => 'rt-reject',
            'user_id' => null,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest Reject',
            'country' => 'GR',
            'license_plate' => 'REJ1',
            'vehicle_type_id' => $vt->id,
            'email' => 'reject@example.test',
            'preferred_locale' => 'en',
            'status' => TempData::STATUS_LATE_SUCCESS,
        ]);

        $staff = $this->actingStaffUser();
        $this->actingAs($staff)
            ->post(route('staff.late-success.reject', ['id' => $temp->id], false))
            ->assertRedirect();

        $temp->refresh();
        $this->assertSame(TempData::STATUS_LATE_REJECTED, (string) $temp->status);
        $this->assertSame('admin_rejected', (string) $temp->resolution_reason);
        $this->assertSame(0, Reservation::query()->count());
    }

    public function test_i_late_success_creates_administrator_alert(): void
    {
        Mail::fake();
        [$drop, $pick] = $this->seedSlots();
        $vt = $this->seedType();
        $date = Carbon::now()->addDays(3)->toDateString();

        TempData::query()->create([
            'merchant_transaction_id' => 'mt-guest-late-alert',
            'retry_token' => 'rt-alert',
            'user_id' => null,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest Alert',
            'country' => 'GR',
            'license_plate' => 'ALRT1',
            'vehicle_type_id' => $vt->id,
            'email' => 'alert@example.test',
            'preferred_locale' => 'en',
            'status' => TempData::STATUS_EXPIRED,
        ]);

        (new PaymentCallbackJob(
            ['merchant_transaction_id' => 'mt-guest-late-alert', 'status' => 'success'],
            ['result' => 'OK']
        ))->handle();

        $alert = AdminAlert::query()->where('type', 'guest_late_success')->first();
        $this->assertNotNull($alert);
        $this->assertSame('mt-guest-late-alert', (string) $alert->merchant_transaction_id);
        $this->assertStringContainsString('force', strtolower((string) $alert->message));
        $this->assertStringContainsString('reject', strtolower((string) $alert->message));
    }

    public function test_agency_late_success_does_not_expose_force_actions(): void
    {
        Mail::fake();
        config()->set('features.advance_payments', true);

        [$drop, $pick] = $this->seedSlots();
        $vt = $this->seedType();
        $date = Carbon::now()->addDays(3)->toDateString();
        $agency = User::factory()->create();

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-agency-late-ui',
            'retry_token' => 'rt-agency',
            'user_id' => $agency->id,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Agency',
            'country' => 'ME',
            'license_plate' => 'AG1',
            'vehicle_type_id' => $vt->id,
            'email' => 'agency-late@example.test',
            'preferred_locale' => 'cg',
            'status' => TempData::STATUS_LATE_SUCCESS,
            'resolution_reason' => 'converted_to_advance',
            'invoice_amount_snapshot' => '50.00',
        ]);

        $this->assertFalse(app(LateSuccessManualResolutionService::class)->exposesManualActions($temp));

        $staff = $this->actingStaffUser();
        $this->actingAs($staff)
            ->get(route('staff.late-success.show', ['id' => $temp->id], false))
            ->assertOk()
            ->assertDontSee('Prisilno kreiraj rezervaciju', false)
            ->assertSee('advance conversion', false);
    }

    public function test_capacity_warning_when_slots_full_but_force_still_allowed(): void
    {
        Mail::fake();
        [$drop, $pick] = $this->seedSlots();
        $vt = $this->seedType();
        $date = Carbon::now()->addDays(3)->toDateString();
        $this->seedParking($date, $drop->id, $pick->id, capacity: 2, reserved: 2, pending: 0);

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-guest-late-full',
            'retry_token' => 'rt-full',
            'user_id' => null,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest Full',
            'country' => 'GR',
            'license_plate' => 'FULL1',
            'vehicle_type_id' => $vt->id,
            'email' => 'full@example.test',
            'preferred_locale' => 'en',
            'status' => TempData::STATUS_LATE_SUCCESS,
        ]);

        $staff = $this->actingStaffUser();
        $this->actingAs($staff)
            ->get(route('staff.late-success.show', ['id' => $temp->id], false))
            ->assertOk()
            ->assertSee('These slots are no longer available', false)
            ->assertSee('Prisilno kreiraj rezervaciju', false);
    }

    public function test_force_resolves_guest_late_success_alert_and_records_admin_identity(): void
    {
        Bus::fake([ProcessReservationAfterPaymentJob::class]);
        Mail::fake();

        [$drop, $pick] = $this->seedSlots();
        $vt = $this->seedType();
        $date = Carbon::now()->addDays(3)->toDateString();
        $this->seedParking($date, $drop->id, $pick->id);

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-guest-late-force-alert',
            'retry_token' => 'rt-force-alert',
            'user_id' => null,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest Force Alert',
            'country' => 'GR',
            'license_plate' => 'FALRT1',
            'vehicle_type_id' => $vt->id,
            'email' => 'force-alert@example.test',
            'preferred_locale' => 'en',
            'status' => TempData::STATUS_LATE_SUCCESS,
            'invoice_amount_snapshot' => '50.00',
        ]);

        $alert = AdminAlert::query()->create([
            'type' => 'guest_late_success',
            'status' => AdminAlert::STATUS_UNREAD,
            'title' => 'Guest Late SUCCESS after expiration — review required',
            'message' => 'Historical message must remain after resolve.',
            'payload_json' => [
                'staff_url' => '/staff/late-success/'.$temp->id,
                'email_full_body' => 'full body archive',
            ],
            'merchant_transaction_id' => $temp->merchant_transaction_id,
            'temp_data_id' => $temp->id,
            'reservation_id' => null,
        ]);
        $createdAt = $alert->created_at?->toDateTimeString();

        $staff = $this->actingStaffUser();
        $this->actingAs($staff)
            ->post(route('staff.late-success.force', ['id' => $temp->id], false))
            ->assertRedirect();

        $alert->refresh();
        $reservation = Reservation::query()->where('merchant_transaction_id', 'mt-guest-late-force-alert')->firstOrFail();

        $this->assertSame(AdminAlert::STATUS_DONE, (string) $alert->status);
        $this->assertNotNull($alert->resolved_at);
        $this->assertSame($createdAt, $alert->created_at?->toDateTimeString());
        $this->assertSame('Guest Late SUCCESS after expiration — review required', (string) $alert->title);
        $this->assertSame('Historical message must remain after resolve.', (string) $alert->message);
        $this->assertSame('full body archive', $alert->payload_json['email_full_body'] ?? null);
        $this->assertSame('force', $alert->payload_json['resolution_action'] ?? null);
        $this->assertSame((int) $staff->id, (int) ($alert->payload_json['resolved_by_admin_user_id'] ?? 0));
        $this->assertSame('late-staff@example.test', (string) ($alert->payload_json['resolved_by_admin_email'] ?? ''));
        $this->assertSame((int) $reservation->id, (int) $alert->reservation_id);
        $this->assertNull($alert->removed_at);
        $this->assertSame(1, AdminAlert::query()->where('type', 'guest_late_success')->count());
    }

    public function test_reject_resolves_guest_late_success_alert_and_records_admin_identity(): void
    {
        Mail::fake();

        [$drop, $pick] = $this->seedSlots();
        $vt = $this->seedType();
        $date = Carbon::now()->addDays(3)->toDateString();

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-guest-late-reject-alert',
            'retry_token' => 'rt-reject-alert',
            'user_id' => null,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Guest Reject Alert',
            'country' => 'GR',
            'license_plate' => 'RALRT1',
            'vehicle_type_id' => $vt->id,
            'email' => 'reject-alert@example.test',
            'preferred_locale' => 'en',
            'status' => TempData::STATUS_LATE_SUCCESS,
        ]);

        $alert = AdminAlert::query()->create([
            'type' => 'guest_late_success',
            'status' => AdminAlert::STATUS_IN_PROGRESS,
            'title' => 'Guest Late SUCCESS after expiration — review required',
            'message' => 'Reject keeps history.',
            'payload_json' => ['staff_url' => '/staff/late-success/'.$temp->id],
            'merchant_transaction_id' => $temp->merchant_transaction_id,
            'temp_data_id' => $temp->id,
        ]);
        $createdAt = $alert->created_at?->toDateTimeString();

        $staff = $this->actingStaffUser();
        $this->actingAs($staff)
            ->post(route('staff.late-success.reject', ['id' => $temp->id], false))
            ->assertRedirect();

        $alert->refresh();
        $this->assertSame(TempData::STATUS_LATE_REJECTED, (string) $temp->fresh()->status);
        $this->assertSame(AdminAlert::STATUS_DONE, (string) $alert->status);
        $this->assertNotNull($alert->resolved_at);
        $this->assertSame($createdAt, $alert->created_at?->toDateTimeString());
        $this->assertSame('Reject keeps history.', (string) $alert->message);
        $this->assertSame('reject', $alert->payload_json['resolution_action'] ?? null);
        $this->assertSame((int) $staff->id, (int) ($alert->payload_json['resolved_by_admin_user_id'] ?? 0));
        $this->assertSame('late-staff@example.test', (string) ($alert->payload_json['resolved_by_admin_email'] ?? ''));
        $this->assertNull($alert->removed_at);
        $this->assertSame(0, Reservation::query()->count());
    }

    public function test_agency_force_is_rejected_server_side_without_resolving_guest_alert_path(): void
    {
        Mail::fake();
        config()->set('features.advance_payments', true);

        [$drop, $pick] = $this->seedSlots();
        $vt = $this->seedType();
        $date = Carbon::now()->addDays(3)->toDateString();
        $agency = User::factory()->create(['email' => 'agency-force-block@example.test']);

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-agency-force-block',
            'retry_token' => 'rt-agency-force',
            'user_id' => $agency->id,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => $date,
            'user_name' => 'Agency Block',
            'country' => 'ME',
            'license_plate' => 'AGFORCE1',
            'vehicle_type_id' => $vt->id,
            'email' => 'agency-force-block@example.test',
            'preferred_locale' => 'cg',
            'status' => TempData::STATUS_LATE_SUCCESS,
            'invoice_amount_snapshot' => '50.00',
        ]);

        $this->assertFalse(app(LateSuccessManualResolutionService::class)->exposesManualActions($temp));
        $this->assertNotNull($temp->user_id);

        $staff = $this->actingStaffUser();
        $result = app(LateSuccessManualResolutionService::class)->forceCreate($temp->id);
        $this->assertFalse($result['ok']);

        $this->actingAs($staff)
            ->post(route('staff.late-success.force', ['id' => $temp->id], false))
            ->assertRedirect();

        $this->assertSame(TempData::STATUS_LATE_SUCCESS, (string) $temp->fresh()->status);
        $this->assertSame(0, Reservation::query()->count());
    }
}
