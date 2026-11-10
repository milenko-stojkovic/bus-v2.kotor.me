<?php

namespace Tests\Feature\Reservation;

use App\Exceptions\HistoricalAttributionException;
use App\Models\Admin;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\ReservationAttribution;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\AdminPanel\Agency\AgencyV2ReservationStatisticsService;
use App\Services\Reservation\HistoricalReservationAttributionService;
use App\Services\Reservation\PanelStatisticsService;
use App\Support\ReservationKind;
use App\Support\ReservationOrigin;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HistoricalV1AttributionTest extends TestCase
{
    use RefreshDatabase;

    private static bool $failNextAttributionCleanup = false;

    public function test_imported_v1_stays_identifiable_after_user_id_is_set(): void
    {
        $agency = $this->agency();
        $reservation = $this->reservation([
            'created_at' => '2026-05-01 12:00:00',
            'updated_at' => '2026-05-01 12:00:00',
            'user_id' => null,
        ]);

        $this->assertTrue(ReservationOrigin::isImportedV1($reservation->created_at));
        $this->assertSame('V1 istorijska, nije pripisana', ReservationOrigin::adminLabel($reservation));

        $reservation->user_id = $agency->id;
        $reservation->save();

        $this->assertTrue(ReservationOrigin::isImportedV1($reservation->fresh()->created_at));
        $this->assertSame('V1 istorijska, pripisana agenciji', ReservationOrigin::adminLabel($reservation->fresh()));
        $this->assertSame('V2 agencijska', ReservationOrigin::adminLabel($this->reservation([
            'user_id' => $agency->id,
            'created_at' => '2026-07-01 12:00:00',
            'updated_at' => '2026-07-01 12:00:00',
        ])));
        $this->assertSame('V2 gost', ReservationOrigin::adminLabel($this->reservation([
            'user_id' => null,
            'created_at' => '2026-07-01 12:00:00',
            'updated_at' => '2026-07-01 12:00:00',
        ])));
    }

    public function test_official_v2_statistics_exclude_attributed_v1_and_panel_statistics_include_them(): void
    {
        $agency = $this->agency('stats@example.com');
        $other = $this->agency('other@example.com');
        $year = (int) now()->year;

        $v1 = $this->reservation([
            'user_id' => $agency->id,
            'created_at' => '2026-04-01 08:00:00',
            'updated_at' => '2026-04-01 08:00:00',
            'reservation_date' => sprintf('%d-04-01', $year),
            'invoice_amount' => '15.00',
            'status' => 'paid',
            'license_plate' => 'KOV1HIST',
        ]);
        $v2 = $this->reservation([
            'user_id' => $agency->id,
            'created_at' => '2026-07-02 08:00:00',
            'updated_at' => '2026-07-02 08:00:00',
            'reservation_date' => sprintf('%d-07-02', $year),
            'invoice_amount' => '20.00',
            'status' => 'paid',
            'license_plate' => 'KOV2ONLY',
        ]);
        $this->reservation([
            'user_id' => $other->id,
            'created_at' => '2026-07-03 08:00:00',
            'updated_at' => '2026-07-03 08:00:00',
            'reservation_date' => sprintf('%d-07-03', $year),
            'invoice_amount' => '40.00',
            'status' => 'paid',
        ]);

        $official = app(AgencyV2ReservationStatisticsService::class)->compute($agency);
        $this->assertSame(1, $official['total_reservations']);
        $this->assertSame(20.0, $official['total_paid_amount']);

        $panel = app(PanelStatisticsService::class)->overview(
            $agency,
            Carbon::parse(sprintf('%d-01-01', $year))->startOfDay(),
            Carbon::parse(sprintf('%d-12-31', $year))->startOfDay(),
        );
        $this->assertSame(35.0, $panel['total_paid']);
        $this->assertSame(2, $panel['visit_count']);
        $plates = $panel['vehicle_usage']->pluck('license_plate')->all();
        $this->assertContains('KOV1HIST', $plates);
        $this->assertContains('KOV2ONLY', $plates);
        $this->assertNotNull($v1->id);
        $this->assertNotNull($v2->id);
    }

    public function test_attribution_changes_only_user_id_and_records_audit_that_survives_account_deletion(): void
    {
        $agency = $this->agency();
        $admin = $this->admin();
        $reservation = $this->reservation([
            'user_id' => null,
            'created_at' => '2026-03-01 10:00:00',
            'updated_at' => '2026-03-01 10:00:00',
            'license_plate' => 'KOKEEP01',
            'invoice_amount' => '15.00',
            'email' => 'old-contact@example.com',
            'user_name' => 'Old Name',
        ]);
        $before = $reservation->fresh()->getAttributes();

        $audit = app(HistoricalReservationAttributionService::class)->attribute(
            $reservation,
            $agency,
            'exact_email',
            $admin,
        );

        $after = $reservation->fresh()->getAttributes();
        $before['user_id'] = $agency->id;
        $this->assertEquals($before, $after);
        $this->assertSame('KOKEEP01', $after['license_plate']);
        $this->assertSame((int) $agency->id, (int) $audit->agency_user_id);
        $this->assertNull($audit->previous_user_id);
        $this->assertSame('exact_email', $audit->evidence);
        $this->assertSame((int) $admin->id, (int) $audit->attributed_by_admin_id);
        $this->assertTrue($audit->isActive());

        $agency->delete();

        $closed = $audit->fresh();
        $this->assertFalse($closed->isActive());
        $this->assertNull($closed->active_reservation_id);
        $this->assertNull($closed->reversed_by_admin_id);
        $this->assertSame(
            HistoricalReservationAttributionService::ACCOUNT_DELETED_EVIDENCE,
            $closed->reversal_evidence,
        );
        $this->assertDatabaseHas('reservation_attributions', [
            'id' => $audit->id,
            'agency_user_id' => $agency->id,
            'evidence' => 'exact_email',
        ]);
        $this->assertNull($reservation->fresh()->user_id);
        $this->assertSame('KOKEEP01', $reservation->fresh()->license_plate);
        $this->assertSame('15.00', $reservation->fresh()->invoice_amount);
        $this->assertSame('Old Name', $reservation->fresh()->user_name);
    }

    public function test_deleted_agency_attribution_can_be_assigned_again_without_touching_native_rows(): void
    {
        $agency = $this->agency('deleted-agency@example.com');
        $replacement = $this->agency('replacement@example.com');
        $admin = $this->admin();
        $service = app(HistoricalReservationAttributionService::class);
        $historical = $this->reservation([
            'user_id' => null,
            'created_at' => '2026-03-02 10:00:00',
            'updated_at' => '2026-03-02 10:00:00',
            'license_plate' => 'KOREUSE1',
            'invoice_amount' => '15.00',
        ]);
        $native = $this->reservation([
            'user_id' => $agency->id,
            'created_at' => '2026-08-02 10:00:00',
            'updated_at' => '2026-08-02 10:00:00',
            'license_plate' => 'KONATIVE',
            'invoice_amount' => '20.00',
        ]);

        $service->attribute($historical, $agency, 'exact_email', $admin);
        $before = $historical->fresh()->getAttributes();

        $agency->delete();

        $historical->refresh();
        $before['user_id'] = null;
        $this->assertEquals($before, $historical->getAttributes());
        $nativeFresh = $native->fresh();
        $this->assertSame('KONATIVE', $nativeFresh->license_plate);
        $this->assertSame('20.00', $nativeFresh->invoice_amount);
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame($agency->id, $nativeFresh->user_id);
        } else {
            $this->assertNull($nativeFresh->user_id);
        }

        try {
            $service->reverse($historical->fresh(), $replacement, $admin, 'already closed');
            $this->fail('A closed attribution was reversed again.');
        } catch (HistoricalAttributionException $e) {
            $this->assertSame(HistoricalAttributionException::NOT_ATTRIBUTED, $e->reason);
        }

        $service->attribute($historical->fresh(), $replacement, 'approved_alias', $admin);

        $history = ReservationAttribution::query()->where('reservation_id', $historical->id)->orderBy('id')->get();
        $this->assertCount(2, $history);
        $this->assertFalse($history[0]->isActive());
        $this->assertSame((int) $agency->id, (int) $history[0]->agency_user_id);
        $this->assertTrue($history[1]->isActive());
        $this->assertSame((int) $replacement->id, (int) $history[1]->agency_user_id);
        $this->assertSame($replacement->id, $historical->fresh()->user_id);
        $this->assertSame('KOREUSE1', $historical->fresh()->license_plate);
        $this->assertSame('15.00', $historical->fresh()->invoice_amount);
        $nativeAfter = $native->fresh();
        $this->assertSame('KONATIVE', $nativeAfter->license_plate);
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame($agency->id, $nativeAfter->user_id);
        } else {
            $this->assertNull($nativeAfter->user_id);
        }
    }

    public function test_failed_attribution_cleanup_rolls_back_account_deletion(): void
    {
        $agency = $this->agency('rollback-agency@example.com');
        $admin = $this->admin();
        $reservation = $this->reservation([
            'user_id' => null,
            'created_at' => '2026-03-03 10:00:00',
            'updated_at' => '2026-03-03 10:00:00',
            'license_plate' => 'KOROLL01',
            'invoice_amount' => '15.00',
            'user_name' => 'Rollback Name',
        ]);
        $audit = app(HistoricalReservationAttributionService::class)->attribute(
            $reservation,
            $agency,
            'exact_email',
            $admin,
        );

        User::deleted(function (): void {
            if (! self::$failNextAttributionCleanup) {
                return;
            }

            self::$failNextAttributionCleanup = false;

            throw new QueryException(
                DB::getDriverName(),
                'update reservation_attributions',
                [],
                new \PDOException('forced attribution cleanup failure'),
            );
        });
        self::$failNextAttributionCleanup = true;

        $this->actingAs($agency)
            ->from('/panel/user')
            ->delete('/profile', [
                'delete_password' => 'password',
            ])
            ->assertRedirect('/panel/user')
            ->assertSessionHasErrorsIn('userDeletion', 'delete_password');

        $this->assertAuthenticatedAs($agency);
        $this->assertNotNull(User::query()->find($agency->id));
        $freshReservation = $reservation->fresh();
        $this->assertSame($agency->id, $freshReservation->user_id);
        $this->assertSame('KOROLL01', $freshReservation->license_plate);
        $this->assertSame('15.00', $freshReservation->invoice_amount);
        $this->assertSame('Rollback Name', $freshReservation->user_name);
        $freshAudit = $audit->fresh();
        $this->assertTrue($freshAudit->isActive());
        $this->assertNull($freshAudit->reversal_evidence);
        $this->assertSame((int) $agency->id, (int) $freshAudit->agency_user_id);
    }

    public function test_reversal_and_reassignment_keep_history_and_reject_conflicts(): void
    {
        $first = $this->agency('first@example.com');
        $second = $this->agency('second@example.com');
        $admin = $this->admin();
        $service = app(HistoricalReservationAttributionService::class);
        $reservation = $this->reservation([
            'user_id' => null,
            'created_at' => '2026-02-01 10:00:00',
            'updated_at' => '2026-02-01 10:00:00',
        ]);

        $service->attribute($reservation, $first, 'approved_name_plate', $admin);

        try {
            $service->attribute($reservation->fresh(), $second, 'approved_name_plate', $admin);
            $this->fail('A second active attribution was accepted.');
        } catch (HistoricalAttributionException $e) {
            $this->assertSame(HistoricalAttributionException::ALREADY_ATTRIBUTED, $e->reason);
        }
        $this->assertSame($first->id, $reservation->fresh()->user_id);

        try {
            $service->reverse($reservation->fresh(), $second, $admin, 'wrong agency');
            $this->fail('Reversal accepted a different agency.');
        } catch (HistoricalAttributionException $e) {
            $this->assertSame(HistoricalAttributionException::ATTRIBUTION_MISMATCH, $e->reason);
        }
        $this->assertSame($first->id, $reservation->fresh()->user_id);

        $service->reverse($reservation->fresh(), $first, $admin, 'corrected match');
        $this->assertNull($reservation->fresh()->user_id);

        $service->attribute($reservation->fresh(), $second, 'approved_alias', $admin);

        $history = ReservationAttribution::query()->where('reservation_id', $reservation->id)->orderBy('id')->get();
        $this->assertCount(2, $history);
        $this->assertNotNull($history[0]->reversed_at);
        $this->assertSame((int) $admin->id, (int) $history[0]->reversed_by_admin_id);
        $this->assertSame('corrected match', $history[0]->reversal_evidence);
        $this->assertNull($history[0]->active_reservation_id);
        $this->assertTrue($history[1]->isActive());
        $this->assertSame($second->id, $reservation->fresh()->user_id);
    }

    public function test_native_v2_guest_and_agency_rows_cannot_be_attributed_by_this_service(): void
    {
        $agency = $this->agency();
        $admin = $this->admin();
        $guest = $this->reservation([
            'user_id' => null,
            'created_at' => '2026-08-01 10:00:00',
            'updated_at' => '2026-08-01 10:00:00',
        ]);
        $native = $this->reservation([
            'user_id' => $agency->id,
            'created_at' => '2026-08-02 10:00:00',
            'updated_at' => '2026-08-02 10:00:00',
        ]);

        $service = app(HistoricalReservationAttributionService::class);
        foreach ([$guest, $native] as $reservation) {
            try {
                $service->attribute($reservation, $agency, 'exact_email', $admin);
                $this->fail('Native V2 reservation was attributed.');
            } catch (HistoricalAttributionException $e) {
                $this->assertSame(HistoricalAttributionException::NOT_IMPORTED_V1, $e->reason);
            }
        }

        $this->assertNull($guest->fresh()->user_id);
        $this->assertSame($agency->id, $native->fresh()->user_id);
        $this->assertSame(0, ReservationAttribution::query()->count());
    }

    public function test_realized_attributed_v1_reservation_rejects_plate_change_and_keeps_snapshot(): void
    {
        $agency = $this->agency();
        $vehicle = Vehicle::query()->create([
            'user_id' => $agency->id,
            'license_plate' => 'KONEW999',
            'vehicle_type_id' => $this->vehicleType()->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $reservation = $this->reservation([
            'user_id' => $agency->id,
            'created_at' => '2026-04-15 10:00:00',
            'updated_at' => '2026-04-15 10:00:00',
            'reservation_date' => '2026-09-01',
            'license_plate' => 'KOOLD001',
            'invoice_amount' => '15.00',
        ]);

        $this->actingAs($agency)
            ->from(route('panel.upcoming', [], false))
            ->patch(route('panel.reservations.vehicle', $reservation, false), [
                'vehicle_id' => $vehicle->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('vehicle_id');

        $fresh = $reservation->fresh();
        $this->assertSame('KOOLD001', $fresh->license_plate);
        $this->assertSame('15.00', $fresh->invoice_amount);
        $this->assertSame('2026-09-01', $fresh->reservation_date->toDateString());
        $this->assertNull($fresh->vehicle_id);
    }

    public function test_admin_reservation_detail_shows_origin_label(): void
    {
        $admin = $this->admin();
        $agency = $this->agency();
        $reservation = $this->reservation([
            'user_id' => $agency->id,
            'created_at' => '2026-01-10 10:00:00',
            'updated_at' => '2026-01-10 10:00:00',
            'license_plate' => 'KOORIGIN',
        ]);

        $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.reservations.show', $reservation, false))
            ->assertOk()
            ->assertSee('Porijeklo', false)
            ->assertSee('V1 istorijska, pripisana agenciji', false)
            ->assertSee('KOORIGIN', false);
    }

    private function agency(string $email = 'agency@example.com'): User
    {
        return User::factory()->create([
            'name' => 'Agency',
            'email' => $email,
        ]);
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'attr-admin-'.uniqid(),
            'email' => 'attr-'.uniqid().'@example.com',
            'password' => bcrypt('secret-password-attr'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function reservation(array $overrides): Reservation
    {
        $slot = ListOfTimeSlot::query()->first() ?? ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $createdAt = $overrides['created_at'] ?? null;
        $updatedAt = $overrides['updated_at'] ?? $createdAt;
        unset($overrides['created_at'], $overrides['updated_at']);

        $reservation = Reservation::query()->create(array_merge([
            'user_name' => 'Historical',
            'country' => 'ME',
            'license_plate' => 'KO'.strtoupper(substr(uniqid(), -5)),
            'email' => 'historical@example.com',
            'vehicle_type_id' => $this->vehicleType()->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => '2026-05-01',
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
            'merchant_transaction_id' => 'mt-'.uniqid(),
        ], $overrides));

        if ($createdAt !== null) {
            DB::table('reservations')->where('id', $reservation->id)->update([
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);

            return $reservation->fresh();
        }

        return $reservation;
    }

    private function vehicleType(): VehicleType
    {
        return VehicleType::query()->first() ?? VehicleType::query()->create(['price' => 15]);
    }
}
