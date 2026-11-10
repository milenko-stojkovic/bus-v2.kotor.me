<?php

namespace Tests\Feature\Reservation;

use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\ReservationAttribution;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\Reservation\HistoricalAttributionBatchDryRun;
use App\Support\ReservationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HistoricalAttributionBatchDryRunTest extends TestCase
{
    use RefreshDatabase;

    private string $reportPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reportPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v1-dry-run-'.uniqid('', true).'.csv';
    }

    protected function tearDown(): void
    {
        if (is_file($this->reportPath)) {
            unlink($this->reportPath);
        }
        parent::tearDown();
    }

    public function test_dry_run_accepts_only_the_approved_criterion_and_writes_nothing(): void
    {
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $this->vehicle($alpha, 'ko š-1', Vehicle::STATUS_REMOVED);
        $this->vehicle($alpha, 'KOS1', Vehicle::STATUS_ACTIVE);

        $accepted = $this->reservation($alpha, [
            'user_id' => null,
            'user_name' => '  alpha   travel ',
            'email' => 'Alpha@Example.com',
            'license_plate' => 'KOS1',
            'created_at' => '2026-04-01 10:00:00',
        ]);
        $native = $this->reservation($alpha, [
            'user_id' => null,
            'user_name' => 'Alpha Travel',
            'email' => 'alpha@example.com',
            'license_plate' => 'KOS1',
            'created_at' => '2026-07-01 10:00:00',
        ]);
        $alreadyLinked = $this->reservation($alpha, [
            'user_id' => $alpha->id,
            'user_name' => 'Alpha Travel',
            'email' => 'alpha@example.com',
            'license_plate' => 'KOS1',
            'created_at' => '2026-04-02 10:00:00',
        ]);
        $wrongEmail = $this->reservation($alpha, [
            'user_id' => null,
            'user_name' => 'Alpha Travel',
            'email' => 'guest@example.com',
            'license_plate' => 'KOS1',
            'created_at' => '2026-04-03 10:00:00',
        ]);

        $sameLeft = $this->agency('Same Name', 'same-left@example.com');
        $this->agency('same   name', 'same-right@example.com');
        $this->vehicle($sameLeft, 'PLATEUNIQD', Vehicle::STATUS_ACTIVE);
        $sharedName = $this->reservation($sameLeft, [
            'user_id' => null,
            'user_name' => 'Same Name',
            'email' => 'same-left@example.com',
            'license_plate' => 'PLATEUNIQD',
            'created_at' => '2026-04-04 10:00:00',
        ]);

        $eff = $this->agency('Eff Travel', 'eff@example.com');
        $gee = $this->agency('Gee Travel', 'gee@example.com');
        $this->vehicle($eff, 'SHARE01', Vehicle::STATUS_ACTIVE);
        $this->vehicle($gee, 'SH-ARE 01', Vehicle::STATUS_REMOVED);
        $sharedPlate = $this->reservation($eff, [
            'user_id' => null,
            'user_name' => 'Eff Travel',
            'email' => 'eff@example.com',
            'license_plate' => 'SHARE01',
            'created_at' => '2026-04-05 10:00:00',
        ]);

        $hotel = $this->agency('Hotel Agency', 'hotel@example.com');
        $this->vehicle($hotel, 'HOTEL1', Vehicle::STATUS_ACTIVE);
        $audited = $this->reservation($hotel, [
            'user_id' => null,
            'user_name' => 'Hotel Agency',
            'email' => 'hotel@example.com',
            'license_plate' => 'HOTEL1',
            'created_at' => '2026-04-06 10:00:00',
        ]);
        DB::table('reservation_attributions')->insert([
            'reservation_id' => $audited->id,
            'active_reservation_id' => $audited->id,
            'agency_user_id' => $hotel->id,
            'previous_user_id' => null,
            'evidence' => 'fixture',
            'attributed_at' => now(),
            'attributed_by_admin_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $duplicate = $this->reservation($alpha, [
            'user_id' => null,
            'user_name' => 'Alpha Travel',
            'email' => 'alpha@example.com',
            'license_plate' => 'KOS1',
            'created_at' => '2026-04-07 10:00:00',
        ]);
        $conflict = $this->reservation($alpha, [
            'user_id' => null,
            'user_name' => 'Alpha Travel',
            'email' => 'alpha@example.com',
            'license_plate' => 'KOS1',
            'created_at' => '2026-04-08 10:00:00',
        ]);
        $wrongCriterion = $this->reservation($alpha, [
            'user_id' => null,
            'user_name' => 'Alpha Travel',
            'email' => 'alpha@example.com',
            'license_plate' => 'KOS1',
            'created_at' => '2026-04-09 10:00:00',
        ]);
        $noPlate = $this->reservation($alpha, [
            'user_id' => null,
            'user_name' => 'Alpha Travel',
            'email' => 'alpha@example.com',
            'license_plate' => 'NOPLATE1',
            'created_at' => '2026-04-10 10:00:00',
        ]);
        $missingAgency = $this->reservation(null, [
            'user_id' => null,
            'user_name' => 'Nobody',
            'email' => 'nobody@example.com',
            'license_plate' => 'NONE1',
            'created_at' => '2026-04-11 10:00:00',
        ]);
        $otherStatus = $this->agency('Other Status', 'other-status@example.com');
        $this->vehicle($otherStatus, 'OTHERST1', 'other');
        $ignoredStatus = $this->reservation($otherStatus, [
            'user_id' => null,
            'user_name' => 'Other Status',
            'email' => 'other-status@example.com',
            'license_plate' => 'OTHERST1',
            'created_at' => '2026-04-12 10:00:00',
        ]);
        $wrongDecision = $this->reservation($alpha, [
            'user_id' => null,
            'user_name' => 'Alpha Travel',
            'email' => 'alpha@example.com',
            'license_plate' => 'KOS1',
            'created_at' => '2026-04-13 10:00:00',
        ]);

        $criterion = HistoricalAttributionBatchDryRun::CRITERION;
        $decision = HistoricalAttributionBatchDryRun::DECISION;
        $csv = $this->csv([
            [$accepted->id, $alpha->id, $criterion, $decision],
            [$native->id, $alpha->id, $criterion, $decision],
            [$alreadyLinked->id, $alpha->id, $criterion, $decision],
            [$wrongEmail->id, $alpha->id, $criterion, $decision],
            [$sharedName->id, $sameLeft->id, $criterion, $decision],
            [$sharedPlate->id, $eff->id, $criterion, $decision],
            [999999, $alpha->id, $criterion, $decision],
            [$audited->id, $hotel->id, $criterion, $decision],
            [$duplicate->id, $alpha->id, $criterion, $decision],
            [$duplicate->id, $alpha->id, $criterion, $decision],
            [$conflict->id, $alpha->id, $criterion, $decision],
            [$conflict->id, $gee->id, $criterion, $decision],
            [$wrongCriterion->id, $alpha->id, 'email_only', $decision],
            [$noPlate->id, $alpha->id, $criterion, $decision],
            [$missingAgency->id, 888888, $criterion, $decision],
            ['abc', $alpha->id, $criterion, $decision],
            [$ignoredStatus->id, $otherStatus->id, $criterion, $decision],
            [$wrongDecision->id, $alpha->id, $criterion, 'APPROVED'],
        ]);

        $this->artisan('reservations:dry-run-v1-attribution', [
            'csv' => $csv,
            '--report' => $this->reportPath,
        ])->expectsOutputToContain('Candidates: 18')
            ->expectsOutputToContain('Accepted: 1')
            ->expectsOutputToContain('Excluded: 17')
            ->expectsOutputToContain('Agencies: 1')
            ->expectsOutputToContain('not_imported_v1: 1')
            ->expectsOutputToContain('user_id_not_null: 1')
            ->expectsOutputToContain('email_mismatch: 1')
            ->expectsOutputToContain('name_not_unique: 1')
            ->expectsOutputToContain('plate_not_exclusive: 1')
            ->expectsOutputToContain('reservation_missing: 1')
            ->expectsOutputToContain('active_attribution_exists: 1')
            ->expectsOutputToContain('duplicate_candidate: 2')
            ->expectsOutputToContain('conflicting_candidate_assignment: 2')
            ->expectsOutputToContain('unexpected_criterion: 1')
            ->expectsOutputToContain('plate_not_in_registry: 2')
            ->expectsOutputToContain('agency_missing: 1')
            ->expectsOutputToContain('invalid_row: 1')
            ->expectsOutputToContain('unexpected_decision: 1')
            ->expectsOutputToContain('user_id='.$alpha->id.' count=1 name=Alpha Travel')
            ->expectsOutputToContain('No reservation or audit row was written.')
            ->assertSuccessful();

        $report = array_map('str_getcsv', file($this->reportPath));
        $this->assertSame(['reservation_id', 'proposed_user_id', 'reason'], $report[0]);
        $this->assertCount(18, $report);
        $reasons = [];
        foreach (array_slice($report, 1) as $line) {
            $reasons[$line[0]] = $line[2];
        }
        $this->assertArrayNotHasKey((string) $accepted->id, $reasons);
        $this->assertSame('not_imported_v1', $reasons[(string) $native->id]);
        $this->assertSame('plate_not_in_registry', $reasons[(string) $ignoredStatus->id]);

        $this->assertNull($accepted->fresh()->user_id);
        $this->assertSame('KOS1', $accepted->fresh()->license_plate);
        $this->assertSame('Alpha@Example.com', $accepted->fresh()->email);
        $this->assertSame($alpha->id, $alreadyLinked->fresh()->user_id);
        $this->assertNull($audited->fresh()->user_id);
        $this->assertSame(1, ReservationAttribution::query()->count());
        unlink($csv);
    }

    public function test_report_path_inside_the_repository_is_refused(): void
    {
        $csv = $this->csv([]);
        $inside = base_path('storage/app/v1-attribution-dry-run-report.csv');

        $this->artisan('reservations:dry-run-v1-attribution', [
            'csv' => $csv,
            '--report' => $inside,
        ])->expectsOutputToContain('outside the repository')
            ->assertFailed();

        $this->assertFileDoesNotExist($inside);
        unlink($csv);
    }

    public function test_missing_csv_does_not_write_a_report(): void
    {
        $this->artisan('reservations:dry-run-v1-attribution', [
            'csv' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'missing-v1-candidates.csv',
            '--report' => $this->reportPath,
        ])->expectsOutputToContain('was not found')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->reportPath);
    }

    public function test_incorrect_name_and_another_agencys_plate_are_rejected_without_writes(): void
    {
        $proposed = $this->agency('Proposed Agency', 'proposed@example.com');
        $owner = $this->agency('Plate Owner', 'owner@example.com');
        $this->vehicle($proposed, 'PROPOSED1', Vehicle::STATUS_ACTIVE);
        $this->vehicle($owner, 'OWNED1', Vehicle::STATUS_REMOVED);

        $wrongName = $this->reservation($proposed, [
            'user_id' => null,
            'user_name' => 'Different Agency',
            'email' => 'proposed@example.com',
            'license_plate' => 'PROPOSED1',
            'created_at' => '2026-03-01 10:00:00',
        ]);
        $otherPlate = $this->reservation($proposed, [
            'user_id' => null,
            'user_name' => 'Proposed Agency',
            'email' => 'proposed@example.com',
            'license_plate' => 'OWNED1',
            'created_at' => '2026-03-02 10:00:00',
        ]);
        $beforeName = $wrongName->user_name;
        $beforePlate = $otherPlate->license_plate;

        $criterion = HistoricalAttributionBatchDryRun::CRITERION;
        $csv = $this->csv([
            [$wrongName->id, $proposed->id, $criterion, HistoricalAttributionBatchDryRun::DECISION],
            [$otherPlate->id, $proposed->id, $criterion, HistoricalAttributionBatchDryRun::DECISION],
        ]);

        $this->artisan('reservations:dry-run-v1-attribution', [
            'csv' => $csv,
            '--report' => $this->reportPath,
        ])->expectsOutputToContain('Accepted: 0')
            ->expectsOutputToContain('name_mismatch: 1')
            ->expectsOutputToContain('plate_not_exclusive: 1')
            ->expectsOutputToContain('No reservation or audit row was written.')
            ->assertSuccessful();

        $this->assertNull($wrongName->fresh()->user_id);
        $this->assertSame($beforeName, $wrongName->fresh()->user_name);
        $this->assertNull($otherPlate->fresh()->user_id);
        $this->assertSame($beforePlate, $otherPlate->fresh()->license_plate);
        $this->assertSame(0, ReservationAttribution::query()->count());
        unlink($csv);
    }

    public function test_invalid_csv_header_fails_without_a_report_or_database_write(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v1-bad-header-'.uniqid('', true).'.csv';
        file_put_contents($path, "id,user\n1,2\n");

        $this->artisan('reservations:dry-run-v1-attribution', [
            'csv' => $path,
            '--report' => $this->reportPath,
        ])->expectsOutputToContain('header')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->reportPath);
        $this->assertSame(0, ReservationAttribution::query()->count());
        unlink($path);
    }

    public function test_report_path_cannot_overwrite_the_candidate_csv(): void
    {
        $csv = $this->csv([
            [1, 1, HistoricalAttributionBatchDryRun::CRITERION, HistoricalAttributionBatchDryRun::DECISION],
        ]);
        $before = file_get_contents($csv);

        $this->artisan('reservations:dry-run-v1-attribution', [
            'csv' => $csv,
            '--report' => $csv,
        ])->expectsOutputToContain('must not be the candidate CSV')
            ->assertFailed();

        $this->assertSame($before, file_get_contents($csv));
        unlink($csv);
    }

    public function test_missing_report_option_fails(): void
    {
        $csv = $this->csv([]);

        $this->artisan('reservations:dry-run-v1-attribution', [
            'csv' => $csv,
        ])->expectsOutputToContain('Pass --report')
            ->assertFailed();

        unlink($csv);
    }

    /**
     * @param  list<array{0: int|string, 1: int|string, 2: string, 3: string}>  $rows
     */
    private function csv(array $rows): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v1-candidates-'.uniqid('', true).'.csv';
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);
        fputcsv($handle, ['reservation_id', 'proposed_user_id', 'criterion', 'decision']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }

    private function agency(string $name, string $email): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
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
    private function reservation(?User $agency, array $overrides): Reservation
    {
        $slot = ListOfTimeSlot::query()->first() ?? ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $createdAt = $overrides['created_at'] ?? '2026-04-01 10:00:00';
        unset($overrides['created_at']);

        $reservation = Reservation::query()->create(array_merge([
            'user_id' => null,
            'user_name' => $agency?->name ?? 'Historical',
            'country' => 'ME',
            'license_plate' => 'KOTEST1',
            'email' => $agency?->email ?? 'historical@example.com',
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
