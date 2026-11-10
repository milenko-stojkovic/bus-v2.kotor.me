<?php

namespace Tests\Feature\Reservation;

use App\Models\Admin;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\ReservationAttribution;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\Reservation\HistoricalAttributionBatchWriter;
use App\Services\Reservation\HistoricalReservationAttributionService;
use App\Support\ReservationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HistoricalAttributionBatchWriterTest extends TestCase
{
    use RefreshDatabase;

    private static bool $perturbNextAttribution = false;

    private static bool $renameAgencyOnNextAudit = false;

    private static bool $sharePlateOnNextAudit = false;

    private string $reportPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reportPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v1-apply-'.uniqid('', true).'.csv';
    }

    protected function tearDown(): void
    {
        self::$perturbNextAttribution = false;
        self::$renameAgencyOnNextAudit = false;
        self::$sharePlateOnNextAudit = false;
        if (is_file($this->reportPath)) {
            unlink($this->reportPath);
        }
        parent::tearDown();
    }

    public function test_confirmed_execution_attributes_only_user_id_and_records_batch_audit(): void
    {
        [$agency, $reservation] = $this->matchingPair('Alpha Travel', 'alpha@example.com', 'KOS1');
        $before = $reservation->fresh()->getAttributes();
        $admin = $this->admin();
        $csv = $this->csv([[$reservation->id, $agency->id]]);

        $this->artisan('reservations:apply-v1-attribution', [
            'csv' => $csv,
            '--report' => $this->reportPath,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatchWriter::CONFIRM_APPLY,
            '--admin-id' => $admin->id,
        ])->expectsOutputToContain('Mode: execute')
            ->expectsOutputToContain('Wrote: 1')
            ->assertSuccessful();

        $after = $reservation->fresh()->getAttributes();
        $before['user_id'] = $agency->id;
        $this->assertEquals($before, $after);
        $audit = ReservationAttribution::query()->first();
        $this->assertNotNull($audit);
        $this->assertTrue($audit->isActive());
        $this->assertSame((int) $agency->id, (int) $audit->agency_user_id);
        $this->assertSame((int) $reservation->id, (int) $audit->reservation_id);
        $this->assertSame(HistoricalAttributionBatchWriter::EVIDENCE, $audit->evidence);
        $this->assertSame((int) $admin->id, (int) $audit->attributed_by_admin_id);
        $this->assertSame(1, ReservationAttribution::query()->count());
        unlink($csv);
    }

    public function test_execution_without_exact_confirmation_writes_nothing(): void
    {
        [$agency, $reservation] = $this->matchingPair('Beta Travel', 'beta@example.com', 'KOBETA1');
        $admin = $this->admin();
        $csv = $this->csv([[$reservation->id, $agency->id]]);

        $this->artisan('reservations:apply-v1-attribution', [
            'csv' => $csv,
            '--report' => $this->reportPath,
        ])->expectsOutputToContain('Mode: preflight')
            ->expectsOutputToContain('Wrote: 0')
            ->expectsOutputToContain('No reservation or audit row was written.')
            ->assertSuccessful();

        $this->artisan('reservations:apply-v1-attribution', [
            'csv' => $csv,
            '--report' => $this->reportPath,
            '--execute' => true,
            '--confirm' => 'APPLY',
            '--admin-id' => $admin->id,
        ])->expectsOutputToContain('Refusing to write')
            ->assertFailed();

        $this->artisan('reservations:apply-v1-attribution', [
            'csv' => $csv,
            '--report' => $this->reportPath,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatchWriter::CONFIRM_APPLY,
        ])->expectsOutputToContain('admin-id')
            ->assertFailed();

        $this->assertNull($reservation->fresh()->user_id);
        $this->assertSame(0, ReservationAttribution::query()->count());
        unlink($csv);
    }

    public function test_invalid_preflight_writes_nothing_including_the_valid_row(): void
    {
        [$agency, $valid] = $this->matchingPair('Gamma Travel', 'gamma@example.com', 'KOGAMMA');
        $native = $this->reservation($agency, [
            'user_id' => null,
            'user_name' => 'Gamma Travel',
            'email' => 'gamma@example.com',
            'license_plate' => 'KOGAMMA',
            'created_at' => '2026-08-01 10:00:00',
        ]);
        $admin = $this->admin();
        $csv = $this->csv([
            [$valid->id, $agency->id],
            [$native->id, $agency->id],
        ]);

        $this->artisan('reservations:apply-v1-attribution', [
            'csv' => $csv,
            '--report' => $this->reportPath,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatchWriter::CONFIRM_APPLY,
            '--admin-id' => $admin->id,
        ])->expectsOutputToContain('Wrote: 0')
            ->expectsOutputToContain('Blocking: 1')
            ->assertFailed();

        $this->assertNull($valid->fresh()->user_id);
        $this->assertNull($native->fresh()->user_id);
        $this->assertSame('KOGAMMA', $native->fresh()->license_plate);
        $this->assertSame(0, ReservationAttribution::query()->count());
        unlink($csv);
    }

    public function test_partial_failure_stops_and_retry_does_not_overwrite_a_foreign_attribution(): void
    {
        [$agency, $first] = $this->matchingPair('Delta Travel', 'delta@example.com', 'KODELTA');
        $second = $this->reservation($agency, [
            'user_id' => null,
            'user_name' => 'Delta Travel',
            'email' => 'delta@example.com',
            'license_plate' => 'KODELTA',
            'created_at' => '2026-04-02 10:00:00',
        ]);
        $intruder = $this->agency('Intruder Travel', 'intruder@example.com');
        $admin = $this->admin();
        $csv = $this->csv([
            [$first->id, $agency->id],
            [$second->id, $agency->id],
        ]);

        self::$perturbNextAttribution = true;
        ReservationAttribution::created(function () use ($second, $intruder): void {
            if (! self::$perturbNextAttribution) {
                return;
            }
            self::$perturbNextAttribution = false;
            DB::table('reservations')->where('id', $second->id)->update([
                'user_id' => $intruder->id,
            ]);
        });

        $writer = app(HistoricalAttributionBatchWriter::class);
        $firstRun = $writer->execute($csv, $admin);
        $this->assertTrue($firstRun['stopped']);
        $this->assertSame(1, $firstRun['wrote']);
        $this->assertSame((string) $second->id, $firstRun['stop_reservation_id']);
        $this->assertSame($agency->id, $first->fresh()->user_id);
        $this->assertSame($intruder->id, $second->fresh()->user_id);

        $retry = $writer->execute($csv, $admin);
        $this->assertFalse($retry['can_write']);
        $this->assertSame(0, $retry['wrote']);
        $this->assertSame($intruder->id, $second->fresh()->user_id);
        $this->assertSame(1, ReservationAttribution::query()->count());
        $this->assertSame(HistoricalAttributionBatchWriter::EVIDENCE, ReservationAttribution::query()->first()->evidence);
        unlink($csv);
    }

    public function test_retry_skips_rows_already_attributed_by_this_batch(): void
    {
        [$agency, $reservation] = $this->matchingPair('Epsilon Travel', 'epsilon@example.com', 'KOEPS1');
        $admin = $this->admin();
        $csv = $this->csv([[$reservation->id, $agency->id]]);
        $writer = app(HistoricalAttributionBatchWriter::class);

        $this->assertSame(1, $writer->execute($csv, $admin)['wrote']);
        $second = $writer->execute($csv, $admin);

        $this->assertTrue($second['can_write']);
        $this->assertSame(0, $second['wrote']);
        $this->assertCount(1, $second['already_done']);
        $this->assertSame(1, ReservationAttribution::query()->count());
        $this->assertSame($agency->id, $reservation->fresh()->user_id);
        unlink($csv);
    }

    public function test_rollback_reverses_only_this_batch_and_keeps_the_snapshot(): void
    {
        [$agency, $batchReservation] = $this->matchingPair('Zeta Travel', 'zeta@example.com', 'KOZETA1');
        $otherAgency = $this->agency('Other Manual', 'manual@example.com');
        $this->vehicle($otherAgency, 'KOMANUAL', Vehicle::STATUS_ACTIVE);
        $manual = $this->reservation($otherAgency, [
            'user_id' => null,
            'user_name' => 'Other Manual',
            'email' => 'manual@example.com',
            'license_plate' => 'KOMANUAL',
            'created_at' => '2026-04-03 10:00:00',
        ]);
        $admin = $this->admin();
        $batchCsv = $this->csv([[$batchReservation->id, $agency->id]]);
        $writer = app(HistoricalAttributionBatchWriter::class);
        $writer->execute($batchCsv, $admin);
        app(HistoricalReservationAttributionService::class)->attribute(
            $manual,
            $otherAgency,
            'manual-other-operation',
            $admin,
        );
        $manualBefore = $manual->fresh()->getAttributes();
        $batchBefore = $batchReservation->fresh()->getAttributes();

        $this->artisan('reservations:apply-v1-attribution', [
            '--report' => $this->reportPath,
            '--rollback' => true,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatchWriter::CONFIRM_APPLY,
            '--admin-id' => $admin->id,
        ])->expectsOutputToContain('Refusing to write')
            ->assertFailed();
        $this->assertSame($agency->id, $batchReservation->fresh()->user_id);

        $this->artisan('reservations:apply-v1-attribution', [
            '--report' => $this->reportPath,
            '--rollback' => true,
        ])->expectsOutputToContain('Mode: rollback-preflight')
            ->expectsOutputToContain('Rollback was not executed.')
            ->assertSuccessful();
        $this->assertSame($agency->id, $batchReservation->fresh()->user_id);

        $this->artisan('reservations:apply-v1-attribution', [
            '--report' => $this->reportPath,
            '--rollback' => true,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatchWriter::CONFIRM_ROLLBACK,
            '--admin-id' => $admin->id,
        ])->expectsOutputToContain('Mode: rollback')
            ->expectsOutputToContain('Reversed: 1')
            ->assertSuccessful();

        $batchAfter = $batchReservation->fresh()->getAttributes();
        $batchBefore['user_id'] = null;
        $this->assertEquals($batchBefore, $batchAfter);
        $this->assertEquals($manualBefore, $manual->fresh()->getAttributes());
        $this->assertSame($otherAgency->id, $manual->fresh()->user_id);
        $batchAudit = ReservationAttribution::query()->where('reservation_id', $batchReservation->id)->first();
        $this->assertNotNull($batchAudit);
        $this->assertFalse($batchAudit->isActive());
        $this->assertSame(HistoricalAttributionBatchWriter::ROLLBACK_EVIDENCE, $batchAudit->reversal_evidence);
        $this->assertTrue(ReservationAttribution::query()->where('reservation_id', $manual->id)->first()->isActive());
        unlink($batchCsv);
    }

    public function test_each_attribution_reads_the_current_identity_tables(): void
    {
        [$agency, $first] = $this->matchingPair('Scan Travel', 'scan@example.com', 'KOSCAN1');
        $second = $this->reservation($agency, [
            'user_id' => null,
            'user_name' => 'Scan Travel',
            'email' => 'scan@example.com',
            'license_plate' => 'KOSCAN1',
            'created_at' => '2026-04-04 10:00:00',
        ]);
        $admin = $this->admin();
        $csv = $this->csv([
            [$first->id, $agency->id],
            [$second->id, $agency->id],
        ]);
        $vehicleScans = 0;
        $userScans = 0;
        DB::listen(function ($query) use (&$vehicleScans, &$userScans): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'from "vehicles"') && str_contains($sql, '"status" in') && ! str_contains($sql, '"user_id" =')) {
                $vehicleScans++;
            }
            if (str_contains($sql, 'from "users"') && str_contains($sql, 'order by "id"')) {
                $userScans++;
            }
        });

        $result = app(HistoricalAttributionBatchWriter::class)->execute($csv, $admin);

        $this->assertSame(2, $result['wrote']);
        $this->assertTrue($result['complete']);
        $this->assertSame(3, $vehicleScans);
        $this->assertSame(3, $userScans);
        $this->assertSame($agency->id, $first->fresh()->user_id);
        $this->assertSame($agency->id, $second->fresh()->user_id);
        unlink($csv);
    }

    public function test_unchanged_watermark_does_not_hide_another_agencys_name_or_plate(): void
    {
        [$agency, $reservation] = $this->matchingPair('Unique Travel', 'unique@example.com', 'KOUNIQUE');
        $other = $this->agency('Different Travel', 'different@example.com');
        $otherVehicle = Vehicle::query()->create([
            'user_id' => $other->id,
            'license_plate' => 'KODIFF1',
            'vehicle_type_id' => VehicleType::query()->first()->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $admin = $this->admin();
        $csv = $this->csv([[$reservation->id, $agency->id]]);
        $writer = app(HistoricalAttributionBatchWriter::class);

        $this->assertTrue($writer->preflight($csv)['can_write']);
        $usersBefore = $this->identityStamp('users');
        $vehiclesBefore = $this->identityStamp('vehicles');
        $preservedNameStamp = DB::table('users')->where('id', $other->id)->value('updated_at');
        DB::table('users')->where('id', $other->id)->update([
            'name' => 'Unique Travel',
            'updated_at' => $preservedNameStamp,
        ]);
        $this->assertSame($usersBefore, $this->identityStamp('users'));
        $this->assertSame($vehiclesBefore, $this->identityStamp('vehicles'));

        $renamed = $writer->execute($csv, $admin);
        $this->assertFalse($renamed['can_write']);
        $this->assertSame(0, $renamed['wrote']);
        $this->assertSame('name_not_unique', $renamed['blocking'][0]['reason']);
        $this->assertNull($reservation->fresh()->user_id);
        $this->assertSame(0, ReservationAttribution::query()->count());
        unlink($csv);

        [$plateAgency, $plateReservation] = $this->matchingPair('Plate Unique', 'plate-unique@example.com', 'KOPLATEU');
        $plateOther = $this->agency('Plate Other', 'plate-other@example.com');
        $plateVehicle = Vehicle::query()->create([
            'user_id' => $plateOther->id,
            'license_plate' => 'KOOTHER9',
            'vehicle_type_id' => VehicleType::query()->first()->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
        $plateCsv = $this->csv([[$plateReservation->id, $plateAgency->id]]);
        $this->assertTrue($writer->preflight($plateCsv)['can_write']);
        $usersBefore = $this->identityStamp('users');
        $vehiclesBefore = $this->identityStamp('vehicles');
        $preservedPlateStamp = DB::table('vehicles')->where('id', $plateVehicle->id)->value('updated_at');
        DB::table('vehicles')->where('id', $plateVehicle->id)->update([
            'license_plate' => 'KOPLATEU',
            'updated_at' => $preservedPlateStamp,
        ]);
        $this->assertSame($usersBefore, $this->identityStamp('users'));
        $this->assertSame($vehiclesBefore, $this->identityStamp('vehicles'));

        $shared = $writer->execute($plateCsv, $admin);
        $this->assertFalse($shared['can_write']);
        $this->assertSame(0, $shared['wrote']);
        $this->assertSame('plate_not_exclusive', $shared['blocking'][0]['reason']);
        $this->assertNull($plateReservation->fresh()->user_id);
        $this->assertSame('KOPLATEU', $plateReservation->fresh()->license_plate);
        $this->assertSame(0, ReservationAttribution::query()->count());
        unlink($plateCsv);
        $this->assertNotNull($otherVehicle->id);
    }

    public function test_conflict_after_the_preflight_read_still_blocks_the_write(): void
    {
        [$agency, $reservation] = $this->matchingPair('Late Name', 'late-name@example.com', 'KOLATE1');
        $other = $this->agency('Late Other', 'late-other@example.com');
        $this->vehicle($other, 'KOLATE9', Vehicle::STATUS_ACTIVE);
        $admin = $this->admin();
        $csv = $this->csv([[$reservation->id, $agency->id]]);
        $preserved = DB::table('users')->where('id', $other->id)->value('updated_at');
        $usersBefore = $this->identityStamp('users');
        $applyConflict = true;
        DB::listen(function ($query) use (&$applyConflict, $other, $preserved): void {
            if (! $applyConflict) {
                return;
            }
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'from "vehicles"') || ! str_contains($sql, '"status" in') || str_contains($sql, '"user_id" =')) {
                return;
            }
            $applyConflict = false;
            DB::table('users')->where('id', $other->id)->update([
                'name' => 'Late Name',
                'updated_at' => $preserved,
            ]);
        });

        $result = app(HistoricalAttributionBatchWriter::class)->execute($csv, $admin);

        $this->assertSame($usersBefore, $this->identityStamp('users'));
        $this->assertTrue($result['stopped']);
        $this->assertSame(0, $result['wrote']);
        $this->assertSame('name_not_unique', $result['stop_reason']);
        $this->assertNull($reservation->fresh()->user_id);
        $this->assertSame(0, ReservationAttribution::query()->count());
        unlink($csv);
    }

    public function test_agency_name_or_plate_change_after_preflight_stops_the_later_row(): void
    {
        [$agency, $first] = $this->matchingPair('Drift Travel', 'drift@example.com', 'KODRIFT');
        $second = $this->reservation($agency, [
            'user_id' => null,
            'user_name' => 'Drift Travel',
            'email' => 'drift@example.com',
            'license_plate' => 'KODRIFT',
            'created_at' => '2026-04-05 10:00:00',
        ]);
        $admin = $this->admin();
        $csv = $this->csv([
            [$first->id, $agency->id],
            [$second->id, $agency->id],
        ]);
        self::$renameAgencyOnNextAudit = true;
        ReservationAttribution::created(function () use ($agency): void {
            if (! self::$renameAgencyOnNextAudit) {
                return;
            }
            self::$renameAgencyOnNextAudit = false;
            $agency->forceFill(['name' => 'Drift Travel Renamed'])->save();
        });

        $renamed = app(HistoricalAttributionBatchWriter::class)->execute($csv, $admin);
        $this->assertTrue($renamed['stopped']);
        $this->assertSame(1, $renamed['wrote']);
        $this->assertSame('name_mismatch', $renamed['stop_reason']);
        $this->assertSame($agency->id, $first->fresh()->user_id);
        $this->assertNull($second->fresh()->user_id);
        $this->assertSame('KODRIFT', $second->fresh()->license_plate);
        unlink($csv);

        [$stable, $kept] = $this->matchingPair('Plate Travel', 'plate@example.com', 'KOPLATE');
        $later = $this->reservation($stable, [
            'user_id' => null,
            'user_name' => 'Plate Travel',
            'email' => 'plate@example.com',
            'license_plate' => 'KOPLATE',
            'created_at' => '2026-04-06 10:00:00',
        ]);
        $intruder = $this->agency('Plate Intruder', 'plate-intruder@example.com');
        $plateCsv = $this->csv([
            [$kept->id, $stable->id],
            [$later->id, $stable->id],
        ]);
        self::$sharePlateOnNextAudit = true;
        ReservationAttribution::created(function () use ($intruder): void {
            if (! self::$sharePlateOnNextAudit) {
                return;
            }
            self::$sharePlateOnNextAudit = false;
            $this->vehicle($intruder, 'KOPLATE', Vehicle::STATUS_ACTIVE);
        });

        $shared = app(HistoricalAttributionBatchWriter::class)->execute($plateCsv, $admin);
        $this->assertTrue($shared['stopped']);
        $this->assertSame(1, $shared['wrote']);
        $this->assertSame('plate_not_exclusive', $shared['stop_reason']);
        $this->assertSame($stable->id, $kept->fresh()->user_id);
        $this->assertNull($later->fresh()->user_id);
        $this->assertSame(2, ReservationAttribution::query()->count());
        unlink($plateCsv);
    }

    public function test_limit_reaches_a_successful_pause_and_retry_finishes_the_batch(): void
    {
        [$agency, $first] = $this->matchingPair('Limit Travel', 'limit@example.com', 'KOLIMIT');
        $second = $this->reservation($agency, [
            'user_id' => null,
            'user_name' => 'Limit Travel',
            'email' => 'limit@example.com',
            'license_plate' => 'KOLIMIT',
            'created_at' => '2026-04-07 10:00:00',
        ]);
        $admin = $this->admin();
        $csv = $this->csv([
            [$first->id, $agency->id],
            [$second->id, $agency->id],
        ]);

        $this->artisan('reservations:apply-v1-attribution', [
            'csv' => $csv,
            '--report' => $this->reportPath,
            '--limit' => 1,
        ])->expectsOutputToContain('Mode: preflight')
            ->expectsOutputToContain('No reservation or audit row was written.')
            ->assertSuccessful();
        $this->assertNull($first->fresh()->user_id);
        $this->assertNull($second->fresh()->user_id);

        $this->artisan('reservations:apply-v1-attribution', [
            'csv' => $csv,
            '--report' => $this->reportPath,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatchWriter::CONFIRM_APPLY,
            '--admin-id' => $admin->id,
            '--limit' => 1,
        ])->expectsOutputToContain('Wrote: 1')
            ->expectsOutputToContain('Remaining: 1')
            ->expectsOutputToContain('Complete: no')
            ->expectsOutputToContain('Limit reached. Ready rows remain for a later invocation.')
            ->assertSuccessful();
        $this->assertSame($agency->id, $first->fresh()->user_id);
        $this->assertNull($second->fresh()->user_id);

        $this->artisan('reservations:apply-v1-attribution', [
            'csv' => $csv,
            '--report' => $this->reportPath,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatchWriter::CONFIRM_APPLY,
            '--admin-id' => $admin->id,
        ])->expectsOutputToContain('Wrote: 1')
            ->expectsOutputToContain('Remaining: 0')
            ->expectsOutputToContain('Complete: yes')
            ->assertSuccessful();
        $this->assertSame($agency->id, $second->fresh()->user_id);
        $this->assertSame(2, ReservationAttribution::query()->count());
        $this->assertSame('KOLIMIT', $first->fresh()->license_plate);
        unlink($csv);
    }

    /**
     * @return array{max_id: int, touched_at: ?string, at_touch: int}
     */
    private function identityStamp(string $table): array
    {
        $maxId = DB::table($table)->max('id');
        $touched = DB::table($table)->max('updated_at');
        $touchedAt = $touched === null ? null : (string) $touched;

        return [
            'max_id' => (int) ($maxId ?? 0),
            'touched_at' => $touchedAt,
            'at_touch' => $touchedAt === null ? 0 : (int) DB::table($table)->where('updated_at', $touchedAt)->count(),
        ];
    }

    /**
     * @param  list<array{0: int, 1: int}>  $rows
     */
    private function csv(array $rows): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v1-apply-src-'.uniqid('', true).'.csv';
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);
        fputcsv($handle, ['reservation_id', 'proposed_user_id', 'criterion', 'decision']);
        foreach ($rows as $row) {
            fputcsv($handle, [
                $row[0],
                $row[1],
                'exact_email_unique_exact_name_exclusive_plate',
                'REVIEW_ONLY',
            ]);
        }
        fclose($handle);

        return $path;
    }

    /**
     * @return array{0: User, 1: Reservation}
     */
    private function matchingPair(string $name, string $email, string $plate): array
    {
        $agency = $this->agency($name, $email);
        $this->vehicle($agency, $plate, Vehicle::STATUS_ACTIVE);
        $reservation = $this->reservation($agency, [
            'user_id' => null,
            'user_name' => $name,
            'email' => $email,
            'license_plate' => $plate,
            'created_at' => '2026-04-01 10:00:00',
        ]);

        return [$agency, $reservation];
    }

    private function agency(string $name, string $email): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
        ]);
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'batch-admin-'.uniqid(),
            'email' => 'batch-'.uniqid().'@example.com',
            'password' => 'secret-password-batch',
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    private function vehicle(User $agency, string $plate, string $status): void
    {
        Vehicle::query()->create([
            'user_id' => $agency->id,
            'license_plate' => $plate,
            'vehicle_type_id' => $this->vehicleType()->id,
            'status' => $status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function reservation(User $agency, array $overrides): Reservation
    {
        $slot = ListOfTimeSlot::query()->first() ?? ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $createdAt = $overrides['created_at'] ?? '2026-04-01 10:00:00';
        unset($overrides['created_at']);

        $reservation = Reservation::query()->create(array_merge([
            'user_id' => null,
            'user_name' => $agency->name,
            'country' => 'ME',
            'license_plate' => 'KOTEST1',
            'email' => $agency->email,
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

        DB::table('reservations')->where('id', $reservation->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $reservation->fresh();
    }

    private function vehicleType(): VehicleType
    {
        return VehicleType::query()->first() ?? VehicleType::query()->create(['price' => 15]);
    }
}
