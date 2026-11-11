<?php

namespace Tests\Feature\Reservation;

use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\ReservationAttribution;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\Reservation\HistoricalAttributionBatch2Preflight;
use App\Services\Reservation\HistoricalAttributionBatchWriter;
use App\Support\ReservationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HistoricalAttributionBatch2PreflightTest extends TestCase
{
    use RefreshDatabase;

    private string $csvPath;

    private string $reportPath;

    /** @var list<string> */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = uniqid('', true);
        $this->csvPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v1-batch2-in-'.$suffix.'.csv';
        $this->reportPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v1-batch2-report-'.$suffix.'.csv';
        $this->writes = [];
    }

    protected function tearDown(): void
    {
        foreach ([$this->csvPath, $this->reportPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_preflight_accepts_every_identity_category_without_date_checks_or_writes(): void
    {
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $beta = $this->agency('Beta Travel', 'beta@example.com');
        $gamma = $this->agency('Gamma Travel', 'gamma@example.com');
        $this->vehicle($alpha, 'KOZ1', Vehicle::STATUS_ACTIVE);
        $this->vehicle($beta, 'SHARE01', Vehicle::STATUS_ACTIVE);
        $this->vehicle($gamma, 'SHARE01', Vehicle::STATUS_REMOVED);
        $this->vehicle($beta, 'TWO1', Vehicle::STATUS_ACTIVE);
        $this->vehicle($gamma, 'TWO1', Vehicle::STATUS_ACTIVE);
        $this->vehicle($beta, 'OWNEDBYBETA', Vehicle::STATUS_REMOVED);

        $exclusive = $this->reservation($alpha, 'Alpha Travel', 'Alpha@Example.com', 'KOZ1');
        DB::table('reservations')->where('id', $exclusive->id)->update([
            'reservation_date' => '2024-01-15',
            'invoice_amount' => '15.00',
            'status' => 'paid',
        ]);
        $shared = $this->reservation($beta, 'Beta Travel', 'beta@example.com', 'share01');
        $foreignShared = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'TWO1');
        $absent = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'MISSING1');
        $foreignExclusive = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'OWNEDBYBETA');
        $staleLabel = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'TWO1');
        $before = (array) DB::table('reservations')->where('id', $exclusive->id)->first();

        $this->writeCsv([
            [$exclusive->id, $alpha->id, 'R'],
            [$shared->id, $beta->id, 'A1'],
            [$foreignShared->id, $alpha->id, 'A2'],
            [$absent->id, $alpha->id, 'B'],
            [$foreignExclusive->id, $alpha->id, 'C'],
            [$staleLabel->id, $alpha->id, 'R'],
        ]);
        $this->listenForWrites();

        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $this->reportPath,
        ]));
        $output = Artisan::output();
        $this->assertStringContainsString('Mode: preflight'.PHP_EOL, $output);
        $this->assertStringContainsString('Accepted: 5'.PHP_EOL, $output);
        $this->assertStringContainsString('Skipped: 1'.PHP_EOL, $output);
        $this->assertStringContainsString('Rejected: 0'.PHP_EOL, $output);
        $this->assertStringContainsString('Wrote: 0'.PHP_EOL, $output);
        $this->assertStringContainsString('plate_category_changed: 1'.PHP_EOL, $output);
        $this->assertStringContainsString('No reservation or audit row was written.'.PHP_EOL, $output);

        $parsed = $this->parsedReport();
        $this->assertSame([
            $exclusive->id => ['outcome' => 'accepted', 'reason' => '', 'current' => 'R'],
            $shared->id => ['outcome' => 'accepted', 'reason' => '', 'current' => 'A1'],
            $foreignShared->id => ['outcome' => 'accepted', 'reason' => '', 'current' => 'A2'],
            $absent->id => ['outcome' => 'accepted', 'reason' => '', 'current' => 'B'],
            $foreignExclusive->id => ['outcome' => 'accepted', 'reason' => '', 'current' => 'C'],
            $staleLabel->id => ['outcome' => 'skipped', 'reason' => 'plate_category_changed', 'current' => 'A2'],
        ], $parsed);
        $this->assertSame($before, (array) DB::table('reservations')->where('id', $exclusive->id)->first());
        $this->assertSame([], $this->writes);
        $this->assertSame(0, ReservationAttribution::query()->count());
        $this->assertStringNotContainsString('alpha@example.com', (string) file_get_contents($this->reportPath));
    }

    public function test_identity_drift_is_rejected_and_a_second_preflight_does_not_write(): void
    {
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $beta = $this->agency('Beta Travel', 'beta@example.com');
        $gamma = $this->agency('Gamma Travel', 'gamma@example.com');
        $this->vehicle($alpha, 'KOZ1', Vehicle::STATUS_ACTIVE);
        $this->agency('Left Case', 'Case@Example.com');
        $right = $this->agency('Right Case', 'case@example.com');

        $rows = [
            'D' => $this->reservation($alpha, 'Beta Travel', 'alpha@example.com', 'KOZ1'),
            'E' => $this->reservation($gamma, 'Gamma Travel', 'nobody@example.com', 'KOZ1'),
            'F' => $this->reservation($right, 'Left Case', 'CASE@example.com', 'KOZ1'),
            'mismatch' => $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1'),
            'missing_agency' => $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1'),
        ];
        $this->writeCsv([
            [$rows['D']->id, $alpha->id, 'R'],
            [$rows['E']->id, $gamma->id, 'B'],
            [$rows['F']->id, $right->id, 'C'],
            [$rows['mismatch']->id, $beta->id, 'R'],
            [$rows['missing_agency']->id, 999999, 'R'],
        ]);
        $this->listenForWrites();

        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $this->reportPath,
        ]));
        $parsed = $this->parsedReport();
        $this->assertSame('not_identity_candidate', $parsed[$rows['D']->id]['reason']);
        $this->assertSame('D', $parsed[$rows['D']->id]['current']);
        $this->assertSame('E', $parsed[$rows['E']->id]['current']);
        $this->assertSame('F', $parsed[$rows['F']->id]['current']);
        $this->assertSame('proposed_user_mismatch', $parsed[$rows['mismatch']->id]['reason']);
        $this->assertSame('R', $parsed[$rows['mismatch']->id]['current']);
        $this->assertSame('agency_missing', $parsed[$rows['missing_agency']->id]['reason']);
        $this->assertSame([], $this->writes);

        $secondReport = $this->reportPath.'.second.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $secondReport,
        ]));
        $this->assertStringContainsString('Rejected: 5'.PHP_EOL, Artisan::output());
        $this->assertSame([], $this->writes);
        if (is_file($secondReport)) {
            unlink($secondReport);
        }
    }

    public function test_existing_attribution_v2_rows_and_duplicate_input_are_rejected(): void
    {
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $this->vehicle($alpha, 'KOZ1', Vehicle::STATUS_ACTIVE);
        $attributed = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        DB::table('reservations')->where('id', $attributed->id)->update(['user_id' => $alpha->id]);
        $audited = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        DB::table('reservation_attributions')->insert([
            'reservation_id' => $audited->id,
            'active_reservation_id' => $audited->id,
            'agency_user_id' => $alpha->id,
            'previous_user_id' => null,
            'evidence' => 'fixture',
            'attributed_at' => now(),
            'attributed_by_admin_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $v2 = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1', '2026-07-01 10:00:00');
        $duplicate = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');

        $this->writeCsv([
            [$attributed->id, $alpha->id, 'R'],
            [$audited->id, $alpha->id, 'R'],
            [$v2->id, $alpha->id, 'R'],
            [$duplicate->id, $alpha->id, 'R'],
            [$duplicate->id, $alpha->id, 'R'],
            ['12abc', (string) $alpha->id, 'D'],
        ]);
        $this->listenForWrites();

        $this->assertSame(1, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $this->reportPath,
        ]));
        $output = Artisan::output();
        $this->assertStringContainsString('Input valid: no'.PHP_EOL, $output);
        $this->assertStringContainsString('malformed or duplicate row.', $output);
        $parsed = $this->parsedReport();
        $this->assertSame('user_id_not_null', $parsed[$attributed->id]['reason']);
        $this->assertSame('active_attribution_exists', $parsed[$audited->id]['reason']);
        $this->assertSame('not_imported_v1', $parsed[$v2->id]['reason']);
        $this->assertSame('duplicate_candidate', $parsed[$duplicate->id]['reason']);
        $this->assertSame([], $this->writes);
        $this->assertSame(1, ReservationAttribution::query()->count());
        $this->assertNull($duplicate->fresh()->user_id);
    }

    public function test_execution_and_rollback_flags_do_not_write(): void
    {
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $this->vehicle($alpha, 'KOZ1', Vehicle::STATUS_ACTIVE);
        $exclusive = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        $before = (array) DB::table('reservations')->where('id', $exclusive->id)->first();
        $this->writeCsv([[$exclusive->id, $alpha->id, 'R']]);
        $this->listenForWrites();

        $this->assertSame(1, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $this->reportPath,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatch2Preflight::CONFIRM_APPLY,
            '--admin-id' => '1',
            '--category' => 'R',
            '--limit' => '7',
        ]));
        $output = Artisan::output();
        $this->assertStringContainsString('Refusing to write.', $output);
        $this->assertSame($before, (array) DB::table('reservations')->where('id', $exclusive->id)->first());
        $this->assertSame(0, ReservationAttribution::query()->count());
        $this->assertSame([], $this->writes);

        $rollbackReport = $this->reportPath.'.rollback.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $rollbackReport,
            '--rollback' => true,
            '--confirm' => HistoricalAttributionBatch2Preflight::CONFIRM_ROLLBACK,
        ]));
        $rollbackOutput = Artisan::output();
        $this->assertStringContainsString('Mode: rollback-preflight'.PHP_EOL, $rollbackOutput);
        $this->assertStringContainsString('Reversed: 0'.PHP_EOL, $rollbackOutput);
        $this->assertSame($before, (array) DB::table('reservations')->where('id', $exclusive->id)->first());
        $this->assertSame([], $this->writes);
        if (is_file($rollbackReport)) {
            unlink($rollbackReport);
        }
    }

    public function test_batch2_evidence_cannot_select_batch1_rows(): void
    {
        foreach (HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY as $category => $evidence) {
            $this->assertSame($category, substr($evidence, -strlen($category)));
            $this->assertStringStartsWith('v1-phase2-batch2:exact_email_unique_exact_name:plate_', $evidence);
            $this->assertNotSame(HistoricalAttributionBatchWriter::EVIDENCE, $evidence);
            $this->assertNotSame(HistoricalAttributionBatchWriter::ROLLBACK_EVIDENCE, $evidence);
        }
        $this->assertNotSame(
            HistoricalAttributionBatchWriter::CONFIRM_ROLLBACK,
            HistoricalAttributionBatch2Preflight::CONFIRM_ROLLBACK,
        );
        foreach (HistoricalAttributionBatch2Preflight::CONFIRM_ROLLBACK_BY_CATEGORY as $confirm) {
            $this->assertNotSame(HistoricalAttributionBatchWriter::CONFIRM_ROLLBACK, $confirm);
        }
    }

    public function test_report_inside_the_repository_is_refused(): void
    {
        $path = base_path('v1-batch2-preflight-should-not-exist.csv');
        $this->writeCsv([['1', '2', 'R']]);

        $this->assertSame(1, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $path,
        ]));
        $this->assertStringContainsString('outside the repository', Artisan::output());
        $this->assertFileDoesNotExist($path);
    }

    /**
     * @param  list<array{0: int|string, 1: int|string, 2: string}>  $rows
     */
    private function writeCsv(array $rows): void
    {
        $handle = fopen($this->csvPath, 'wb');
        $this->assertNotFalse($handle);
        fputcsv($handle, ['reservation_id', 'proposed_user_id', 'plate_category']);
        foreach ($rows as $row) {
            fputcsv($handle, [(string) $row[0], (string) $row[1], $row[2]]);
        }
        fclose($handle);
    }

    /**
     * @return array<int, array{outcome: string, reason: string, current: string}>
     */
    private function parsedReport(): array
    {
        $handle = fopen($this->reportPath, 'rb');
        $this->assertNotFalse($handle);
        $header = fgetcsv($handle);
        $this->assertSame([
            'reservation_id',
            'proposed_user_id',
            'proposed_plate_category',
            'outcome',
            'reason',
            'current_plate_category',
        ], $header);
        $parsed = [];
        while (($row = fgetcsv($handle)) !== false) {
            $parsed[(int) $row[0]] = [
                'outcome' => (string) $row[3],
                'reason' => (string) $row[4],
                'current' => (string) $row[5],
            ];
        }
        fclose($handle);

        return $parsed;
    }

    private function listenForWrites(): void
    {
        DB::listen(function ($query): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query->sql) === 1) {
                $this->writes[] = $query->sql;
            }
        });
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

    private function reservation(User $agency, string $name, string $email, string $plate, string $createdAt = '2026-04-01 10:00:00'): Reservation
    {
        $slot = ListOfTimeSlot::query()->first() ?? ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $reservation = Reservation::query()->create([
            'user_id' => null,
            'user_name' => $name,
            'country' => 'ME',
            'license_plate' => $plate,
            'email' => $email,
            'vehicle_type_id' => $this->vehicleType()->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => '2026-05-01',
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
            'merchant_transaction_id' => 'mt-'.uniqid('', true),
        ]);

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
