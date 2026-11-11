<?php

namespace Tests\Feature\Reservation;

use App\Models\Admin;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\ReservationAttribution;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\Reservation\HistoricalAttributionBatch2Preflight;
use App\Services\Reservation\HistoricalAttributionBatch2Writer;
use App\Services\Reservation\HistoricalAttributionBatchWriter;
use App\Services\Reservation\HistoricalReservationAttributionService;
use App\Support\ReservationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HistoricalAttributionBatch2WriterTest extends TestCase
{
    use RefreshDatabase;

    private string $csvPath;

    private string $reportPath;

    private ?object $queryGate = null;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = uniqid('', true);
        $this->csvPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v1-batch2-write-'.$suffix.'.csv';
        $this->reportPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v1-batch2-write-report-'.$suffix.'.csv';
    }

    protected function tearDown(): void
    {
        if ($this->queryGate !== null) {
            $this->queryGate->on = false;
        }
        foreach ([$this->csvPath, $this->reportPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_execution_writes_each_category_and_leaves_business_fields_unchanged(): void
    {
        $admin = $this->admin();
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $beta = $this->agency('Beta Travel', 'beta@example.com');
        $gamma = $this->agency('Gamma Travel', 'gamma@example.com');
        $this->vehicle($alpha, 'KOZ1', Vehicle::STATUS_ACTIVE);
        $this->vehicle($beta, 'SHARE01', Vehicle::STATUS_ACTIVE);
        $this->vehicle($gamma, 'SHARE01', Vehicle::STATUS_REMOVED);
        $this->vehicle($beta, 'TWO1', Vehicle::STATUS_ACTIVE);
        $this->vehicle($gamma, 'TWO1', Vehicle::STATUS_ACTIVE);
        $this->vehicle($beta, 'OWNEDBYBETA', Vehicle::STATUS_REMOVED);

        $exclusive = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        DB::table('reservations')->where('id', $exclusive->id)->update(['reservation_date' => '2024-01-15']);
        $shared = $this->reservation($beta, 'Beta Travel', 'beta@example.com', 'SHARE01');
        $foreignShared = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'TWO1');
        $absent = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'MISSING1');
        $foreignExclusive = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'OWNEDBYBETA');
        $before = (array) DB::table('reservations')->where('id', $exclusive->id)->first();

        $this->writeCsv([
            [$exclusive->id, $alpha->id, 'R'],
            [$shared->id, $beta->id, 'A1'],
            [$foreignShared->id, $alpha->id, 'A2'],
            [$absent->id, $alpha->id, 'B'],
            [$foreignExclusive->id, $alpha->id, 'C'],
        ]);

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', $this->executeArgs($admin)));
        $output = Artisan::output();
        $this->assertStringContainsString('Mode: execute'.PHP_EOL, $output);
        $this->assertStringContainsString('Wrote: 5'.PHP_EOL, $output);
        $this->assertStringContainsString('Rejected: 0'.PHP_EOL, $output);

        $before['user_id'] = $alpha->id;
        $this->assertEquals($before, (array) DB::table('reservations')->where('id', $exclusive->id)->first());
        $this->assertSame($beta->id, $shared->fresh()->user_id);
        $this->assertSame($alpha->id, $foreignShared->fresh()->user_id);
        $this->assertSame($alpha->id, $absent->fresh()->user_id);
        $this->assertSame($alpha->id, $foreignExclusive->fresh()->user_id);

        $evidence = ReservationAttribution::query()->orderBy('reservation_id')->pluck('evidence', 'reservation_id')->all();
        $this->assertSame(HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['R'], $evidence[$exclusive->id]);
        $this->assertSame(HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['A1'], $evidence[$shared->id]);
        $this->assertSame(HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['A2'], $evidence[$foreignShared->id]);
        $this->assertSame(HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['B'], $evidence[$absent->id]);
        $this->assertSame(HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['C'], $evidence[$foreignExclusive->id]);
        $this->assertNoCommitBetweenIdentityReadAndAuditInsert($statements);

        $doc = (new \ReflectionClass(HistoricalAttributionBatch2Writer::class))->getDocComment();
        $this->assertIsString($doc);
        $this->assertStringContainsString('REPEATABLE READ', $doc);
        $this->assertStringContainsString('serializability', $doc);
    }

    public function test_transaction_local_validation_rejects_a_changed_identity_or_plate_and_rolls_back(): void
    {
        $admin = $this->admin();
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $beta = $this->agency('Beta Travel', 'beta@example.com');
        $gamma = $this->agency('Gamma Travel', 'gamma@example.com');
        $delta = $this->agency('Delta Travel', 'delta@example.com');
        $this->vehicle($alpha, 'ALPHA1', Vehicle::STATUS_ACTIVE);
        $this->vehicle($beta, 'BETA1', Vehicle::STATUS_ACTIVE);
        $this->vehicle($gamma, 'GAMMA1', Vehicle::STATUS_ACTIVE);
        $this->vehicle($delta, 'DELTA1', Vehicle::STATUS_ACTIVE);
        $this->vehicle($delta, 'DELTA2', Vehicle::STATUS_ACTIVE);
        $emailChanged = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'ALPHA1');
        $nameChanged = $this->reservation($beta, 'Beta Travel', 'beta@example.com', 'BETA1');
        $colliding = $this->reservation($gamma, 'Gamma Travel', 'gamma@example.com', 'GAMMA1');
        $plateChanged = $this->reservation($delta, 'Delta Travel', 'delta@example.com', 'DELTA1');
        $stable = $this->reservation($delta, 'Delta Travel', 'delta@example.com', 'DELTA2');
        $plateBefore = (array) DB::table('reservations')->where('id', $plateChanged->id)->first();

        $this->writeCsv([
            [$emailChanged->id, $alpha->id, 'R'],
            [$nameChanged->id, $beta->id, 'R'],
            [$colliding->id, $gamma->id, 'R'],
            [$plateChanged->id, $delta->id, 'R'],
            [$stable->id, $delta->id, 'R'],
        ]);

        // The cheap reservation read has no identity map. This change is visible
        // to the maps read inside the attribution transaction.
        $this->queryGate = (object) ['on' => true];
        $gate = $this->queryGate;
        DB::listen(function ($query) use ($gate, $alpha, $beta): void {
            if (! $gate->on) {
                return;
            }
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'reservations') || ! str_contains($sql, 'created_at')) {
                return;
            }
            $gate->on = false;
            $alpha->forceFill(['email' => 'alpha-changed@example.com'])->save();
            $beta->forceFill(['name' => 'Beta Renamed'])->save();
            $this->agency('Gamma Twin', 'Gamma@Example.com');
            $this->vehicle($alpha, 'DELTA1', Vehicle::STATUS_REMOVED);
        });

        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', $this->executeArgs($admin)));
        $gate->on = false;
        $parsed = $this->parsedReport();
        $this->assertSame('rejected', $parsed[$emailChanged->id]['outcome']);
        $this->assertSame('not_identity_candidate', $parsed[$emailChanged->id]['reason']);
        $this->assertSame('not_identity_candidate', $parsed[$nameChanged->id]['reason']);
        $this->assertSame('not_identity_candidate', $parsed[$colliding->id]['reason']);
        $this->assertSame('skipped', $parsed[$plateChanged->id]['outcome']);
        $this->assertSame('plate_category_changed', $parsed[$plateChanged->id]['reason']);
        $this->assertSame('written', $parsed[$stable->id]['outcome']);
        $this->assertNull($emailChanged->fresh()->user_id);
        $this->assertNull($nameChanged->fresh()->user_id);
        $this->assertNull($colliding->fresh()->user_id);
        $this->assertNull($plateChanged->fresh()->user_id);
        $this->assertSame($delta->id, $stable->fresh()->user_id);
        $this->assertSame(1, ReservationAttribution::query()->count());
        $this->assertEquals($plateBefore, (array) DB::table('reservations')->where('id', $plateChanged->id)->first());
    }

    public function test_limit_category_rerun_and_existing_audit_do_not_duplicate_rows(): void
    {
        $admin = $this->admin();
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $beta = $this->agency('Beta Travel', 'beta@example.com');
        $this->vehicle($alpha, 'KOZ1', Vehicle::STATUS_ACTIVE);
        $this->vehicle($beta, 'BETA1', Vehicle::STATUS_ACTIVE);
        $first = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        $second = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        $otherCategory = $this->reservation($beta, 'Beta Travel', 'beta@example.com', 'BETA1');
        $blocked = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        DB::table('reservation_attributions')->insert([
            'reservation_id' => $blocked->id,
            'active_reservation_id' => $blocked->id,
            'agency_user_id' => $alpha->id,
            'previous_user_id' => null,
            'evidence' => 'fixture',
            'attributed_at' => now(),
            'attributed_by_admin_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->writeCsv([
            [$first->id, $alpha->id, 'R'],
            [$second->id, $alpha->id, 'R'],
            [$otherCategory->id, $beta->id, 'B'],
            [$blocked->id, $alpha->id, 'R'],
            [999999, $alpha->id, 'R'],
        ]);

        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', $this->executeArgs($admin, 'R', 1)));
        $this->assertSame($alpha->id, $first->fresh()->user_id);
        $this->assertNull($second->fresh()->user_id);
        $this->assertNull($otherCategory->fresh()->user_id);
        $this->assertSame(1, ReservationAttribution::query()->where('evidence', HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['R'])->count());

        $secondReport = $this->reportPath.'.second.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $secondReport,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatch2Preflight::CONFIRM_APPLY,
            '--admin-id' => $admin->id,
            '--category' => 'R',
            '--limit' => 1,
        ]));
        $this->assertSame($alpha->id, $second->fresh()->user_id);
        $this->assertSame(2, ReservationAttribution::query()->where('evidence', HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['R'])->count());
        $this->assertNull($blocked->fresh()->user_id);
        $this->assertStringContainsString('Already done: 1'.PHP_EOL, Artisan::output());

        $thirdReport = $this->reportPath.'.third.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $thirdReport,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatch2Preflight::CONFIRM_APPLY,
            '--admin-id' => $admin->id,
            '--category' => 'R',
            '--limit' => 10,
        ]));
        $third = $this->parsedReport($thirdReport);
        $this->assertSame('already_done', $third[$first->id]['outcome']);
        $this->assertSame('already_done', $third[$second->id]['outcome']);
        $this->assertSame('not_selected', $third[$otherCategory->id]['outcome']);
        $this->assertSame('active_attribution_exists', $third[$blocked->id]['reason']);
        $this->assertSame('reservation_missing', $third[999999]['reason']);
        $this->assertNull($blocked->fresh()->user_id);
        $this->assertNull($otherCategory->fresh()->user_id);
        $this->assertSame(2, ReservationAttribution::query()->where('evidence', HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['R'])->count());
        $this->assertStringContainsString('Wrote: 0'.PHP_EOL, Artisan::output());
        foreach ([$secondReport, $thirdReport] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_execute_requires_category_limit_and_confirmation(): void
    {
        $admin = $this->admin();
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $this->vehicle($alpha, 'KOZ1', Vehicle::STATUS_ACTIVE);
        $reservation = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        $this->writeCsv([[$reservation->id, $alpha->id, 'R']]);

        $this->assertSame(1, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $this->reportPath,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatch2Preflight::CONFIRM_APPLY,
            '--admin-id' => $admin->id,
            '--limit' => 1,
        ]));
        $this->assertNull($reservation->fresh()->user_id);

        $limitedReport = $this->reportPath.'.limit.csv';
        $this->assertSame(1, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $limitedReport,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatch2Preflight::CONFIRM_APPLY,
            '--admin-id' => $admin->id,
            '--category' => 'R',
            '--limit' => 51,
        ]));
        $this->assertStringContainsString('1 to 50', Artisan::output());
        $this->assertNull($reservation->fresh()->user_id);
        if (is_file($limitedReport)) {
            unlink($limitedReport);
        }
    }

    public function test_rollback_is_limited_to_batch2_and_can_target_one_category(): void
    {
        $admin = $this->admin();
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $beta = $this->agency('Beta Travel', 'beta@example.com');
        $this->vehicle($alpha, 'KOZ1', Vehicle::STATUS_ACTIVE);
        $batch2R = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        $batch2B = $this->reservation($beta, 'Beta Travel', 'beta@example.com', 'MISSINGB');
        $batch1 = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        app(HistoricalReservationAttributionService::class)->attribute(
            $batch1,
            $alpha,
            HistoricalAttributionBatchWriter::EVIDENCE,
            $admin,
        );

        $this->writeCsv([
            [$batch2R->id, $alpha->id, 'R'],
            [$batch2B->id, $beta->id, 'B'],
        ]);
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', $this->executeArgs($admin, 'all', 10)));
        $this->assertSame($alpha->id, $batch1->fresh()->user_id);

        $wrongReport = $this->reportPath.'.wrong.csv';
        $this->assertSame(1, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $wrongReport,
            '--rollback' => true,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatch2Preflight::CONFIRM_ROLLBACK,
            '--admin-id' => $admin->id,
            '--category' => 'R',
            '--limit' => 10,
        ]));
        $this->assertSame($alpha->id, $batch2R->fresh()->user_id);

        $categoryReport = $this->reportPath.'.category.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $categoryReport,
            '--rollback' => true,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatch2Preflight::CONFIRM_ROLLBACK_BY_CATEGORY['R'],
            '--admin-id' => $admin->id,
            '--category' => 'R',
            '--limit' => 10,
        ]));
        $this->assertNull($batch2R->fresh()->user_id);
        $this->assertSame($beta->id, $batch2B->fresh()->user_id);
        $this->assertSame($alpha->id, $batch1->fresh()->user_id);
        $closed = ReservationAttribution::query()->where('reservation_id', $batch2R->id)->first();
        $this->assertNotNull($closed);
        $this->assertNull($closed->active_reservation_id);
        $this->assertSame(HistoricalAttributionBatch2Writer::ROLLBACK_EVIDENCE_BY_CATEGORY['R'], $closed->reversal_evidence);
        $this->assertSame(HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['R'], $closed->evidence);

        $allReport = $this->reportPath.'.all.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $allReport,
            '--rollback' => true,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatch2Preflight::CONFIRM_ROLLBACK,
            '--admin-id' => $admin->id,
            '--category' => 'all',
            '--limit' => 10,
        ]));
        $this->assertNull($batch2B->fresh()->user_id);
        $this->assertSame($alpha->id, $batch1->fresh()->user_id);
        $closedB = ReservationAttribution::query()->where('reservation_id', $batch2B->id)->first();
        $this->assertNotNull($closedB);
        $this->assertSame(HistoricalAttributionBatch2Writer::ROLLBACK_EVIDENCE_BY_CATEGORY['B'], $closedB->reversal_evidence);

        $batch1Report = $this->reportPath.'.batch1.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-attribution', [
            '--report' => $batch1Report,
            '--rollback' => true,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatchWriter::CONFIRM_ROLLBACK,
            '--admin-id' => $admin->id,
        ]));
        $this->assertNull($batch1->fresh()->user_id);
        $this->assertNull($batch2B->fresh()->user_id);
        $this->assertSame(
            HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['B'],
            ReservationAttribution::query()->where('reservation_id', $batch2B->id)->value('evidence'),
        );

        foreach ([$wrongReport, $categoryReport, $allReport, $batch1Report] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_reversed_batch2_row_requires_manual_review_and_rollback_stays_inside_the_csv(): void
    {
        $admin = $this->admin();
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $beta = $this->agency('Beta Travel', 'beta@example.com');
        $this->vehicle($alpha, 'KOZ1', Vehicle::STATUS_ACTIVE);
        $target = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        $kept = $this->reservation($beta, 'Beta Travel', 'beta@example.com', 'MISSINGB');
        $fresh = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        $batch1 = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        $batch1Reversed = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        $before = (array) DB::table('reservations')->where('id', $target->id)->first();
        app(HistoricalReservationAttributionService::class)->attribute($batch1, $alpha, HistoricalAttributionBatchWriter::EVIDENCE, $admin);
        app(HistoricalReservationAttributionService::class)->attribute($batch1Reversed, $alpha, HistoricalAttributionBatchWriter::EVIDENCE, $admin);
        app(HistoricalReservationAttributionService::class)->reverse($batch1Reversed, $alpha, $admin, HistoricalAttributionBatchWriter::ROLLBACK_EVIDENCE);

        $this->writeCsv([
            [$target->id, $alpha->id, 'R'],
            [$kept->id, $beta->id, 'B'],
        ]);
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', $this->executeArgs($admin, 'all', 10)));
        $this->assertSame($alpha->id, $target->fresh()->user_id);
        $this->assertSame($beta->id, $kept->fresh()->user_id);
        $this->assertSame(1, ReservationAttribution::query()->where('reservation_id', $target->id)->count());

        $subset = $this->csvPath.'.subset.csv';
        $subsetReport = $this->reportPath.'.subset.csv';
        $this->writeCsv([[$target->id, $alpha->id, 'R']], $subset);
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $subset,
            '--report' => $subsetReport,
            '--rollback' => true,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatch2Preflight::CONFIRM_ROLLBACK,
            '--admin-id' => $admin->id,
            '--category' => 'all',
            '--limit' => 10,
        ]));
        $this->assertStringContainsString('Scope: csv'.PHP_EOL, Artisan::output());
        $this->assertNull($target->fresh()->user_id);
        $this->assertSame($beta->id, $kept->fresh()->user_id);
        $this->assertSame($alpha->id, $batch1->fresh()->user_id);
        $this->assertEquals($before, (array) DB::table('reservations')->where('id', $target->id)->first());

        $rerunReport = $this->reportPath.'.rerun.csv';
        $this->writeCsv([
            [$target->id, $alpha->id, 'R'],
            [$kept->id, $beta->id, 'B'],
            [$fresh->id, $alpha->id, 'R'],
            [$batch1Reversed->id, $alpha->id, 'R'],
        ]);
        $vehicleReads = 0;
        DB::listen(function ($query) use (&$vehicleReads): void {
            if (preg_match('/from\s+"?vehicles"?/i', $query->sql) === 1) {
                $vehicleReads++;
            }
        });
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $rerunReport,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatch2Preflight::CONFIRM_APPLY,
            '--admin-id' => $admin->id,
            '--category' => 'all',
            '--limit' => 10,
        ]));
        $parsed = $this->parsedReport($rerunReport);
        $this->assertSame('manual_review', $parsed[$target->id]['outcome']);
        $this->assertSame(HistoricalAttributionBatch2Preflight::REASON_CLOSED_BATCH2, $parsed[$target->id]['reason']);
        $this->assertSame('already_done', $parsed[$kept->id]['outcome']);
        $this->assertSame('written', $parsed[$fresh->id]['outcome']);
        $this->assertSame('written', $parsed[$batch1Reversed->id]['outcome']);
        $this->assertNull($target->fresh()->user_id);
        $this->assertSame(1, ReservationAttribution::query()->where('reservation_id', $target->id)->count());
        $this->assertSame($beta->id, $kept->fresh()->user_id);
        $this->assertSame($alpha->id, $fresh->fresh()->user_id);
        $this->assertSame($alpha->id, $batch1->fresh()->user_id);
        $this->assertSame($alpha->id, $batch1Reversed->fresh()->user_id);
        $this->assertSame(2, $vehicleReads);
        $this->assertEquals($before, (array) DB::table('reservations')->where('id', $target->id)->first());
        $rerunOutput = Artisan::output();
        $this->assertStringContainsString('Manual review: 1'.PHP_EOL, $rerunOutput);
        $this->assertStringContainsString('Attempts: 2'.PHP_EOL, $rerunOutput);

        $batch1Report = $this->reportPath.'.batch1-hold.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-attribution', [
            '--report' => $batch1Report,
            '--rollback' => true,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatchWriter::CONFIRM_ROLLBACK,
            '--admin-id' => $admin->id,
        ]));
        $this->assertNull($batch1->fresh()->user_id);
        $this->assertSame($alpha->id, $fresh->fresh()->user_id);
        $this->assertSame($alpha->id, $batch1Reversed->fresh()->user_id);
        $this->assertSame($beta->id, $kept->fresh()->user_id);
        $this->assertNull($target->fresh()->user_id);

        foreach ([$subset, $subsetReport, $rerunReport, $batch1Report] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_attempt_limit_skips_completed_rows_without_rebuilding_identity_maps(): void
    {
        $admin = $this->admin();
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $this->vehicle($alpha, 'KOZ1', Vehicle::STATUS_ACTIVE);
        $first = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        $second = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        $third = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        $this->writeCsv([
            [$first->id, $alpha->id, 'R'],
            [$second->id, $alpha->id, 'R'],
            [$third->id, $alpha->id, 'R'],
        ]);

        $vehicleReads = 0;
        DB::listen(function ($query) use (&$vehicleReads): void {
            if (preg_match('/from\s+"?vehicles"?/i', $query->sql) === 1) {
                $vehicleReads++;
            }
        });
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $this->reportPath,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatch2Preflight::CONFIRM_APPLY,
            '--admin-id' => $admin->id,
            '--category' => 'R',
            '--limit' => 3,
            '--attempts' => 1,
        ]));
        $this->assertSame($alpha->id, $first->fresh()->user_id);
        $this->assertNull($second->fresh()->user_id);
        $this->assertNull($third->fresh()->user_id);
        $this->assertSame(1, $vehicleReads);
        $this->assertStringContainsString('Beyond attempts: 2'.PHP_EOL, Artisan::output());

        $secondReport = $this->reportPath.'.next.csv';
        $vehicleReads = 0;
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', [
            'csv' => $this->csvPath,
            '--report' => $secondReport,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatch2Preflight::CONFIRM_APPLY,
            '--admin-id' => $admin->id,
            '--category' => 'R',
            '--limit' => 2,
            '--attempts' => 1,
        ]));
        $parsed = $this->parsedReport($secondReport);
        $this->assertSame('already_done', $parsed[$first->id]['outcome']);
        $this->assertSame('written', $parsed[$second->id]['outcome']);
        $this->assertSame('beyond_attempts', $parsed[$third->id]['outcome']);
        $this->assertSame($alpha->id, $second->fresh()->user_id);
        $this->assertNull($third->fresh()->user_id);
        $this->assertSame(1, $vehicleReads);
        $this->assertStringContainsString('Attempts: 1'.PHP_EOL, Artisan::output());
        if (is_file($secondReport)) {
            unlink($secondReport);
        }
    }

    public function test_closed_batch2_audit_discovered_inside_the_transaction_does_not_rebuild_maps(): void
    {
        $admin = $this->admin();
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $this->vehicle($alpha, 'KOZ1', Vehicle::STATUS_ACTIVE);
        $reservation = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        $before = (array) DB::table('reservations')->where('id', $reservation->id)->first();
        $this->writeCsv([[$reservation->id, $alpha->id, 'R']]);

        $this->queryGate = (object) ['on' => true];
        $gate = $this->queryGate;
        DB::listen(function ($query) use ($gate, $reservation, $alpha, $admin): void {
            if (! $gate->on || ! str_contains(strtolower($query->sql), 'reservation_attributions')) {
                return;
            }
            $gate->on = false;
            DB::table('reservation_attributions')->insert([
                'reservation_id' => $reservation->id,
                'active_reservation_id' => null,
                'agency_user_id' => $alpha->id,
                'previous_user_id' => null,
                'evidence' => HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['R'],
                'attributed_at' => now(),
                'attributed_by_admin_id' => $admin->id,
                'reversed_at' => now(),
                'reversed_by_admin_id' => $admin->id,
                'reversal_evidence' => HistoricalAttributionBatch2Writer::ROLLBACK_EVIDENCE_BY_CATEGORY['R'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $vehicleReads = 0;
        DB::listen(function ($query) use (&$vehicleReads): void {
            if (preg_match('/from\s+"?vehicles"?/i', $query->sql) === 1) {
                $vehicleReads++;
            }
        });

        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch2', $this->executeArgs($admin, 'R', 1)));
        $parsed = $this->parsedReport();
        $this->assertSame('manual_review', $parsed[$reservation->id]['outcome']);
        $this->assertSame(HistoricalAttributionBatch2Preflight::REASON_CLOSED_BATCH2, $parsed[$reservation->id]['reason']);
        $this->assertNull($reservation->fresh()->user_id);
        $this->assertSame(0, $vehicleReads);
        $this->assertSame(1, ReservationAttribution::query()->count());
        $this->assertEquals($before, (array) DB::table('reservations')->where('id', $reservation->id)->first());
        $this->assertStringContainsString('Attempts: 0'.PHP_EOL, Artisan::output());
    }

    /**
     * @param  list<array{0: int, 1: int, 2: string}>  $rows
     */
    private function writeCsv(array $rows, ?string $path = null): void
    {
        $handle = fopen($path ?? $this->csvPath, 'wb');
        $this->assertNotFalse($handle);
        fputcsv($handle, ['reservation_id', 'proposed_user_id', 'plate_category']);
        foreach ($rows as $row) {
            fputcsv($handle, [(string) $row[0], (string) $row[1], $row[2]]);
        }
        fclose($handle);
    }

    /**
     * @return array<string, mixed>
     */
    private function executeArgs(?Admin $admin = null, string $category = 'all', int $limit = 10): array
    {
        return [
            'csv' => $this->csvPath,
            '--report' => $this->reportPath,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatch2Preflight::CONFIRM_APPLY,
            '--admin-id' => ($admin ?? $this->admin())->id,
            '--category' => $category,
            '--limit' => $limit,
        ];
    }

    /**
     * @param  list<string>  $statements
     */
    private function assertNoCommitBetweenIdentityReadAndAuditInsert(array $statements): void
    {
        $insertAt = null;
        foreach ($statements as $index => $sql) {
            if (preg_match('/insert\s+into\s+"?reservation_attributions"?/i', $sql) === 1) {
                $insertAt = $index;
                break;
            }
        }
        $this->assertNotNull($insertAt);
        $vehicleAt = null;
        for ($index = $insertAt - 1; $index >= 0; $index--) {
            if (preg_match('/from\s+"?vehicles"?/i', $statements[$index]) === 1) {
                $vehicleAt = $index;
                break;
            }
        }
        $this->assertNotNull($vehicleAt);
        $between = implode("\n", array_slice($statements, $vehicleAt + 1, $insertAt - $vehicleAt - 1));
        $this->assertDoesNotMatchRegularExpression('/\b(commit|release)\b/i', $between);
    }

    /**
     * @return array<int, array{outcome: string, reason: string}>
     */
    private function parsedReport(?string $path = null): array
    {
        $handle = fopen($path ?? $this->reportPath, 'rb');
        $this->assertNotFalse($handle);
        fgetcsv($handle);
        $parsed = [];
        while (($row = fgetcsv($handle)) !== false) {
            $parsed[(int) $row[0]] = [
                'outcome' => (string) $row[3],
                'reason' => (string) $row[4],
            ];
        }
        fclose($handle);

        return $parsed;
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'batch2-admin-'.uniqid(),
            'email' => 'batch2-'.uniqid('', true).'@example.com',
            'password' => 'secret-password-batch',
            'control_access' => false,
            'admin_access' => true,
        ]);
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

    private function reservation(User $agency, string $name, string $email, string $plate): Reservation
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
            'created_at' => '2026-04-01 10:00:00',
            'updated_at' => '2026-04-01 10:00:00',
        ]);

        return $reservation->fresh();
    }

    private function vehicleType(): VehicleType
    {
        return VehicleType::query()->first() ?? VehicleType::query()->create(['price' => 15]);
    }
}
