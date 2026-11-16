<?php

namespace Tests\Feature\Reservation;

use App\Console\Commands\ApplyV1Batch3Command;
use App\Console\Commands\PrepareV1Batch3Command;
use App\Models\Admin;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\ReservationAttribution;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Reservation\HistoricalAttributionBatch2DryRun;
use App\Services\Reservation\HistoricalAttributionBatch2Preflight;
use App\Services\Reservation\HistoricalAttributionBatch3Catalog;
use App\Services\Reservation\HistoricalAttributionBatch3DryRun;
use App\Services\Reservation\HistoricalAttributionBatch3Writer;
use App\Services\Reservation\HistoricalAttributionBatchWriter;
use App\Support\ReservationKind;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HistoricalAttributionBatch3WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v1b3-'.bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_single_row_decision_matches_the_batch_classification(): void
    {
        $alpha = $this->agency(11, 'Alpha Travel', 'alpha@example.com');
        $beta = $this->agency(12, 'Beta Travel', 'beta@example.com');
        $this->reservation($alpha->name, 'visitor@example.com', 'DE');
        $this->reservation($alpha->name, 'alpha@example.com', 'ME');
        $this->reservation($alpha->name, '', 'ME');
        $this->reservation('Unknown Desk', 'other@example.com', 'ME');
        $this->reservation($beta->name, 'shared-name@example.com', 'ME');
        $this->agency(13, 'Beta Travel', 'beta-two@example.com');

        $dryRun = app(HistoricalAttributionBatch3DryRun::class);
        $classification = $dryRun->classify();
        $snapshot = app(HistoricalAttributionBatch2DryRun::class)->currentIdentitySnapshot();
        $this->assertTrue($classification['reconciliation_passed']);
        foreach ($classification['rows'] as $row) {
            $reservation = DB::table('reservations')->where('id', $row['reservation_id'])->first();
            $audits = DB::table('reservation_attributions')->where('reservation_id', $row['reservation_id'])->get()->all();
            $decision = $dryRun->classifyRow($reservation, $audits, $snapshot);
            $this->assertSame($row['class'], $decision['class']);
            $this->assertSame($row['name_owner_user_id'], $decision['name_owner_user_id']);
        }
    }

    public function test_production_prepare_requires_the_frozen_baseline_and_an_outside_directory(): void
    {
        $this->assertSame(2060, HistoricalAttributionBatch3Catalog::EXPECTED_CLASS8);
        $this->assertSame(8, HistoricalAttributionBatch3Catalog::EXPECTED_HOLD);
        $this->assertSame([148, 224, 464, 515], HistoricalAttributionBatch3Catalog::HOLD_AGENCY_IDS);
        $this->assertSame(50, HistoricalAttributionBatch3Catalog::GROUP_SIZE);
        $schedule = (string) file_get_contents(base_path('routes/console.php'));
        $this->assertStringNotContainsString('prepare-v1-batch3', $schedule);
        $this->assertStringNotContainsString('approve-v1-batch3-group', $schedule);
        $this->assertStringNotContainsString('apply-v1-batch3', $schedule);
        $this->assertStringNotContainsString('reconcile-v1-batch3', $schedule);

        $writes = $this->watchWrites();
        $this->artisan('reservations:prepare-v1-batch3', ['--out' => $this->root])
            ->expectsOutputToContain('requires 2060')
            ->assertFailed();
        $this->assertSame([], $writes);
        $this->assertSame([], array_diff(scandir($this->root) ?: [], ['.', '..']));

        $inside = base_path('v1-batch3-prepare-should-not-exist');
        $this->artisan('reservations:prepare-v1-batch3', ['--out' => $inside])
            ->expectsOutputToContain('outside the repository')
            ->assertFailed();
        $this->assertDirectoryDoesNotExist($inside);
    }

    public function test_prepare_freezes_sorted_groups_and_approval_rejects_a_changed_source(): void
    {
        $admin = $this->admin();
        $hold = $this->agency(148, 'Roman', 'roman@example.com');
        $ordinary = $this->agency(6, 'Alpha Travel', 'alpha@example.com');
        $later = $this->reservation($ordinary->name, 'later@example.com', 'ME');
        $earlier = $this->reservation($hold->name, 'held@example.com', 'FR');
        $middle = $this->reservation($ordinary->name, 'middle@example.com', 'ME');
        $this->reservation($ordinary->name, 'alpha@example.com', 'ME');
        $this->useBaseline(3, 1);

        $writes = $this->watchWrites();
        $this->assertSame(0, Artisan::call('reservations:prepare-v1-batch3', ['--out' => $this->root]));
        $this->assertSame([], $writes);
        $manifest = json_decode((string) file_get_contents($this->root.DIRECTORY_SEPARATOR.'manifest.json'), true);
        $this->assertTrue($manifest['frozen']);
        $this->assertSame(3, $manifest['baseline']['class8']);
        $this->assertSame(1, $manifest['baseline']['hold']);
        $this->assertSame(2, $manifest['baseline']['eligible']);
        $this->assertSame([$earlier->id], array_column($manifest['hold']['rows'], 'reservation_id'));
        $this->assertSame([$later->id, $middle->id], array_column($manifest['groups'][0]['rows'], 'reservation_id'));
        $eligiblePath = $this->root.DIRECTORY_SEPARATOR.'eligible'.DIRECTORY_SEPARATOR.'v1-batch3-eligible-001.csv';
        $this->assertSame($manifest['groups'][0]['sha256'], hash_file('sha256', $eligiblePath));

        file_put_contents($eligiblePath, str_replace('eligible', 'eligiblX', (string) file_get_contents($eligiblePath)));
        $approved = $this->root.DIRECTORY_SEPARATOR.'approved-001.csv';
        $this->files[] = $approved;
        $this->files[] = substr($approved, 0, -4).'.approval.json';
        $this->artisan('reservations:approve-v1-batch3-group', [
            'csv' => $eligiblePath,
            '--manifest' => $this->root.DIRECTORY_SEPARATOR.'manifest.json',
            '--out' => $approved,
            '--confirm' => HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE,
            '--admin-id' => $admin->id,
        ])->expectsOutputToContain('does not match the frozen manifest')->assertFailed();
        $this->assertFileDoesNotExist($approved);
    }

    public function test_groups_do_not_exceed_fifty_rows(): void
    {
        $agency = $this->agency(6, 'Alpha Travel', 'alpha@example.com');
        $ids = [];
        for ($index = 0; $index < 51; $index++) {
            $ids[] = $this->reservation($agency->name, 'guest-'.$index.'@example.com', 'ME')->id;
        }
        sort($ids);
        $this->useBaseline(51, 0);
        $this->assertSame(0, Artisan::call('reservations:prepare-v1-batch3', ['--out' => $this->root]));
        $manifest = json_decode((string) file_get_contents($this->root.DIRECTORY_SEPARATOR.'manifest.json'), true);
        $this->assertCount(2, $manifest['groups']);
        $this->assertCount(50, $manifest['groups'][0]['rows']);
        $this->assertCount(1, $manifest['groups'][1]['rows']);
        $this->assertSame(array_slice($ids, 0, 50), array_column($manifest['groups'][0]['rows'], 'reservation_id'));
        $this->assertSame(array_slice($ids, 50), array_column($manifest['groups'][1]['rows'], 'reservation_id'));
        $this->artisan('reservations:prepare-v1-batch3', ['--out' => $this->root])
            ->expectsOutputToContain('not empty')
            ->assertFailed();
    }

    public function test_writer_follows_precedence_limits_rollback_and_reconciliation(): void
    {
        $admin = $this->admin();
        $alpha = $this->agency(6, 'Alpha Travel', 'alpha@example.com');
        $beta = $this->agency(8, 'Beta Travel', 'beta@example.com');
        $held = $this->agency(148, 'Roman', 'roman@example.com');
        $first = $this->reservation($alpha->name, 'one@example.com', 'DE');
        $second = $this->reservation($alpha->name, 'two@example.com', 'ME');
        $third = $this->reservation($beta->name, 'one@example.com', 'ME');
        $heldRow = $this->reservation($held->name, 'held@example.com', 'ME');
        $this->useBaseline(4, 1);
        $this->assertSame(0, Artisan::call('reservations:prepare-v1-batch3', ['--out' => $this->root]));

        $eligible = $this->root.DIRECTORY_SEPARATOR.'eligible'.DIRECTORY_SEPARATOR.'v1-batch3-eligible-001.csv';
        $hold = $this->root.DIRECTORY_SEPARATOR.'hold'.DIRECTORY_SEPARATOR.'v1-batch3-hold.csv';
        $approvedDir = $this->root.DIRECTORY_SEPARATOR.'approved';
        mkdir($approvedDir);
        $approved = $approvedDir.DIRECTORY_SEPARATOR.'group.csv';
        $this->assertSame(0, Artisan::call('reservations:approve-v1-batch3-group', $this->approveArgs($admin, $eligible, $approved, HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE)));
        $record = json_decode((string) file_get_contents(substr($approved, 0, -4).'.approval.json'), true);
        $this->assertSame(HistoricalAttributionBatch3Catalog::EVIDENCE, $record['evidence']);
        $this->assertSame($admin->id, $record['admin_id']);
        $this->assertSame(hash_file('sha256', $approved), $record['approved_sha256']);
        $this->assertSame(hash_file('sha256', $eligible), $record['source_sha256']);

        $report = $this->root.DIRECTORY_SEPARATOR.'preflight.csv';
        $writes = $this->watchWrites();
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch3', ['csv' => $approved, '--report' => $report]));
        $this->assertSame([], $writes);
        $this->assertStringContainsString('Mode: preflight'.PHP_EOL, Artisan::output());
        $this->assertSame('accepted', $this->report($report)[$first->id]['outcome']);

        $execute = $this->root.DIRECTORY_SEPARATOR.'execute-limit.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch3', $this->executeArgs($admin, $approved, $execute, 1, 1)));
        $limited = $this->report($execute);
        $this->assertSame('written', $limited[$first->id]['outcome']);
        $this->assertSame(HistoricalAttributionBatch3Catalog::EVIDENCE, $limited[$first->id]['evidence']);
        $this->assertSame('beyond_limit', $limited[$second->id]['outcome']);
        $this->assertSame('beyond_limit', $limited[$third->id]['outcome']);
        $this->assertSame($alpha->id, $first->fresh()->user_id);
        $this->assertNull($second->fresh()->user_id);

        $resume = $this->root.DIRECTORY_SEPARATOR.'execute-resume.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch3', $this->executeArgs($admin, $approved, $resume, 5, 1)));
        $resumed = $this->report($resume);
        $this->assertSame('already_done', $resumed[$first->id]['outcome']);
        $this->assertSame('written', $resumed[$second->id]['outcome']);
        $this->assertSame('beyond_attempts', $resumed[$third->id]['outcome']);
        $this->assertSame(2, ReservationAttribution::query()->count());

        $finish = $this->root.DIRECTORY_SEPARATOR.'execute-finish.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch3', $this->executeArgs($admin, $approved, $finish, 5, 5)));
        $this->assertSame('already_done', $this->report($finish)[$first->id]['outcome']);
        $this->assertSame('written', $this->report($finish)[$third->id]['outcome']);
        $this->assertSame($beta->id, $third->fresh()->user_id);
        $this->assertSame(3, ReservationAttribution::query()->count());

        $this->assertSame(0, Artisan::call('reservations:reconcile-v1-batch3', [
            '--manifest' => $this->root.DIRECTORY_SEPARATOR.'manifest.json',
            '--approved-dir' => $approvedDir,
        ]));
        $reconciled = Artisan::output();
        $this->assertStringContainsString('Pending: 0'.PHP_EOL, $reconciled);
        $this->assertStringContainsString('Active Batch 3: 3'.PHP_EOL, $reconciled);

        $outside = $this->reservation($beta->name, 'outside@example.com', 'ME');
        DB::table('reservation_attributions')->insert($this->auditRow($outside->id, $beta->id, HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['R'], $outside->id));
        DB::table('reservations')->where('id', $outside->id)->update(['user_id' => $beta->id]);
        $batch2Before = DB::table('reservation_attributions')->where('reservation_id', $outside->id)->first();
        $rollbackReport = $this->root.DIRECTORY_SEPARATOR.'rollback.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch3', [
            'csv' => $approved,
            '--report' => $rollbackReport,
            '--rollback' => true,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatch3Catalog::CONFIRM_ROLLBACK,
            '--admin-id' => $admin->id,
            '--limit' => 1,
        ]));
        $this->assertSame('reversed', $this->rollbackReport($rollbackReport)[$first->id]['outcome']);
        $this->assertNull($first->fresh()->user_id);
        $this->assertSame($alpha->id, $second->fresh()->user_id);
        $this->assertEquals($batch2Before, DB::table('reservation_attributions')->where('reservation_id', $outside->id)->first());
        $this->assertSame(1, ReservationAttribution::query()->where('evidence', HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['R'])->whereNotNull('active_reservation_id')->count());

        $afterRollback = $this->root.DIRECTORY_SEPARATOR.'after-rollback.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch3', $this->executeArgs($admin, $approved, $afterRollback, 5, 5)));
        $this->assertSame('manual_review', $this->report($afterRollback)[$first->id]['outcome']);
        $this->assertSame('closed_batch3_audit', $this->report($afterRollback)[$first->id]['reason']);
        $this->assertNull($first->fresh()->user_id);
        $this->assertSame('already_done', $this->report($afterRollback)[$second->id]['outcome']);

        $craftedDir = $this->root.DIRECTORY_SEPARATOR.'crafted';
        mkdir($craftedDir);
        $crafted = $craftedDir.DIRECTORY_SEPARATOR.'held.csv';
        $this->craft($crafted, [[$heldRow->id, $held->id]], HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE, $admin->id);
        $heldReport = $this->root.DIRECTORY_SEPARATOR.'held.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch3', $this->executeArgs($admin, $crafted, $heldReport, 5, 5)));
        $this->assertSame('manual_review', $this->report($heldReport)[$heldRow->id]['outcome']);
        $this->assertSame('ambiguous_agency_name', $this->report($heldReport)[$heldRow->id]['reason']);
        $this->assertNull($heldRow->fresh()->user_id);

        $freshOrdinary = $this->reservation($alpha->name, 'fresh-ordinary@example.com', 'ME');
        $ordinaryReview = $craftedDir.DIRECTORY_SEPARATOR.'review-ordinary.csv';
        $this->craft($ordinaryReview, [[$freshOrdinary->id, $alpha->id]], HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE_REVIEW, $admin->id);
        $reviewReport = $this->root.DIRECTORY_SEPARATOR.'review-ordinary.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch3', $this->executeArgs($admin, $ordinaryReview, $reviewReport, 5, 5, HistoricalAttributionBatch3Catalog::CONFIRM_APPLY_REVIEW)));
        $this->assertSame('rejected', $this->report($reviewReport)[$freshOrdinary->id]['outcome']);
        $this->assertSame('review_scope_mismatch', $this->report($reviewReport)[$freshOrdinary->id]['reason']);
        $this->assertNull($freshOrdinary->fresh()->user_id);

        $this->artisan('reservations:approve-v1-batch3-group', $this->approveArgs($admin, $eligible, $approvedDir.DIRECTORY_SEPARATOR.'again.csv', HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE_REVIEW, [$first->id]))
            ->expectsOutputToContain('hold file')
            ->assertFailed();
        $reviewed = $approvedDir.DIRECTORY_SEPARATOR.'reviewed.csv';
        $this->assertSame(0, Artisan::call('reservations:approve-v1-batch3-group', $this->approveArgs($admin, $hold, $reviewed, HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE_REVIEW, [$heldRow->id])));
        $this->assertSame(HistoricalAttributionBatch3Catalog::EVIDENCE_REVIEWED, json_decode((string) file_get_contents(substr($reviewed, 0, -4).'.approval.json'), true)['evidence']);
        $reviewedReport = $this->root.DIRECTORY_SEPARATOR.'reviewed.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch3', $this->executeArgs($admin, $reviewed, $reviewedReport, 5, 5, HistoricalAttributionBatch3Catalog::CONFIRM_APPLY_REVIEW)));
        $this->assertSame('written', $this->report($reviewedReport)[$heldRow->id]['outcome']);
        $this->assertSame(HistoricalAttributionBatch3Catalog::EVIDENCE_REVIEWED, $this->report($reviewedReport)[$heldRow->id]['evidence']);
        $this->assertSame($held->id, $heldRow->fresh()->user_id);

        file_put_contents($approved, str_replace('approved', 'approvXd', (string) file_get_contents($approved)));
        $tampered = $this->root.DIRECTORY_SEPARATOR.'tampered.csv';
        $writes = $this->watchWrites();
        $this->artisan('reservations:apply-v1-batch3', $this->executeArgs($admin, $approved, $tampered, 5, 5))
            ->expectsOutputToContain('no longer matches')
            ->assertFailed();
        $this->assertSame([], $writes);
        $this->assertFileDoesNotExist($tampered);

        $classification = $this->root.DIRECTORY_SEPARATOR.'classification.csv';
        file_put_contents($classification, "reservation_id,class,name_owner_user_id\n1,name_unique_email_unregistered,6\n");
        $this->artisan('reservations:apply-v1-batch3', ['csv' => $classification, '--report' => $this->root.DIRECTORY_SEPARATOR.'class-report.csv'])
            ->expectsOutputToContain('classification or diagnostic CSV')
            ->assertFailed();
        $this->artisan('reservations:apply-v1-batch3', ['csv' => $eligible, '--report' => $this->root.DIRECTORY_SEPARATOR.'eligible-report.csv'])
            ->expectsOutputToContain('cannot be executed')
            ->assertFailed();

        $doc = (new \ReflectionClass(HistoricalAttributionBatch3Writer::class))->getDocComment();
        $this->assertIsString($doc);
        $this->assertStringContainsString('REPEATABLE READ', $doc);
        $this->assertStringContainsString('SQLite tests run on one connection', $doc);
    }

    public function test_conflicts_identity_changes_and_a_failed_row_do_not_overwrite_earlier_work(): void
    {
        $admin = $this->admin();
        $alpha = $this->agency(6, 'Alpha Travel', 'alpha@example.com');
        $beta = $this->agency(8, 'Beta Travel', 'beta@example.com');
        $stable = $this->reservation($alpha->name, 'stable@example.com', 'ME');
        $renamed = $this->reservation($alpha->name, 'renamed@example.com', 'ME');
        $conflict = $this->reservation($beta->name, 'conflict@example.com', 'ME');
        $bareUser = $this->reservation($beta->name, 'bare@example.com', 'ME');
        $disagreement = $this->reservation($alpha->name, 'disagree@example.com', 'ME');
        $afterStop = $this->reservation($alpha->name, 'after-stop@example.com', 'ME');
        $this->useBaseline(6, 0);
        $this->assertSame(0, Artisan::call('reservations:prepare-v1-batch3', ['--out' => $this->root]));
        $eligible = $this->root.DIRECTORY_SEPARATOR.'eligible'.DIRECTORY_SEPARATOR.'v1-batch3-eligible-001.csv';
        $approvedDir = $this->root.DIRECTORY_SEPARATOR.'approved';
        mkdir($approvedDir);
        $approved = $approvedDir.DIRECTORY_SEPARATOR.'group.csv';
        $this->assertSame(0, Artisan::call('reservations:approve-v1-batch3-group', $this->approveArgs($admin, $eligible, $approved, HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE)));

        DB::table('reservations')->where('id', $renamed->id)->update(['user_name' => 'No Such Agency']);
        DB::table('reservation_attributions')->insert($this->auditRow($conflict->id, $beta->id, HistoricalAttributionBatchWriter::EVIDENCE, $conflict->id));
        DB::table('reservations')->where('id', $conflict->id)->update(['user_id' => $beta->id]);
        DB::table('reservations')->where('id', $bareUser->id)->update(['user_id' => $beta->id]);
        DB::table('reservation_attributions')->insert($this->auditRow($disagreement->id, $alpha->id, HistoricalAttributionBatch3Catalog::EVIDENCE, $disagreement->id));

        $report = $this->root.DIRECTORY_SEPARATOR.'conflicts.csv';
        $this->assertSame(1, Artisan::call('reservations:apply-v1-batch3', $this->executeArgs($admin, $approved, $report, 10, 10)));
        $parsed = $this->report($report);
        $this->assertSame('written', $parsed[$stable->id]['outcome']);
        $this->assertSame('rejected', $parsed[$renamed->id]['outcome']);
        $this->assertSame('no_longer_candidate:name_unmatched', $parsed[$renamed->id]['reason']);
        $this->assertSame('active_audit_conflict', $parsed[$conflict->id]['reason']);
        $this->assertSame('user_id_without_batch3_audit', $parsed[$bareUser->id]['reason']);
        $this->assertSame('stopped', $parsed[$disagreement->id]['outcome']);
        $this->assertSame('audit_user_disagreement', $parsed[$disagreement->id]['reason']);
        $this->assertArrayNotHasKey($afterStop->id, $parsed);
        $this->assertSame($alpha->id, $stable->fresh()->user_id);
        $this->assertNull($renamed->fresh()->user_id);
        $this->assertSame($beta->id, $conflict->fresh()->user_id);
        $this->assertSame($beta->id, $bareUser->fresh()->user_id);
        $this->assertNull($disagreement->fresh()->user_id);
        $this->assertNull($afterStop->fresh()->user_id);
        $this->assertSame(HistoricalAttributionBatchWriter::EVIDENCE, DB::table('reservation_attributions')->where('reservation_id', $conflict->id)->value('evidence'));

        $this->app->make(Kernel::class)->registerCommand(new class extends ApplyV1Batch3Command
        {
            protected function writeReportRows($handle, array $header, array $rows): void
            {
                fputcsv($handle, ['partial']);

                throw new \RuntimeException('The report file could not be written.');
            }
        });
        $partial = $this->root.DIRECTORY_SEPARATOR.'partial.csv';
        $this->artisan('reservations:apply-v1-batch3', $this->executeArgs($admin, $approved, $partial, 10, 10))
            ->expectsOutputToContain('could not be written')
            ->assertFailed();
        $this->assertFileDoesNotExist($partial);
        $this->assertSame($alpha->id, $stable->fresh()->user_id);
    }

    public function test_a_failed_row_rolls_back_and_the_same_approved_file_resumes(): void
    {
        $admin = $this->admin();
        $alpha = $this->agency(6, 'Alpha Travel', 'alpha@example.com');
        $first = $this->reservation($alpha->name, 'first@example.com', 'ME');
        $second = $this->reservation($alpha->name, 'second@example.com', 'ME');
        $this->useBaseline(2, 0);
        $this->assertSame(0, Artisan::call('reservations:prepare-v1-batch3', ['--out' => $this->root]));
        $eligible = $this->root.DIRECTORY_SEPARATOR.'eligible'.DIRECTORY_SEPARATOR.'v1-batch3-eligible-001.csv';
        $approvedDir = $this->root.DIRECTORY_SEPARATOR.'approved';
        mkdir($approvedDir);
        $approved = $approvedDir.DIRECTORY_SEPARATOR.'group.csv';
        $this->assertSame(0, Artisan::call('reservations:approve-v1-batch3-group', $this->approveArgs($admin, $eligible, $approved, HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE)));

        $gate = (object) ['on' => true, 'inserts' => 0];
        DB::listen(function ($query) use ($gate): void {
            if (! $gate->on || preg_match('/insert\s+into\s+"?reservation_attributions"?/i', $query->sql) !== 1) {
                return;
            }
            $gate->inserts++;
            if ($gate->inserts === 2) {
                $gate->on = false;
                throw new \RuntimeException('injected failure');
            }
        });
        $failed = $this->root.DIRECTORY_SEPARATOR.'failed.csv';
        $this->assertSame(1, Artisan::call('reservations:apply-v1-batch3', $this->executeArgs($admin, $approved, $failed, 5, 5)));
        $this->assertSame('written', $this->report($failed)[$first->id]['outcome']);
        $this->assertSame('stopped', $this->report($failed)[$second->id]['outcome']);
        $this->assertSame('unexpected_failure', $this->report($failed)[$second->id]['reason']);
        $this->assertSame($alpha->id, $first->fresh()->user_id);
        $this->assertNull($second->fresh()->user_id);
        $this->assertSame(1, ReservationAttribution::query()->count());

        $resumed = $this->root.DIRECTORY_SEPARATOR.'resumed-after-failure.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch3', $this->executeArgs($admin, $approved, $resumed, 5, 5)));
        $this->assertSame('already_done', $this->report($resumed)[$first->id]['outcome']);
        $this->assertSame('written', $this->report($resumed)[$second->id]['outcome']);
        $this->assertSame($alpha->id, $second->fresh()->user_id);
        $this->assertSame(2, ReservationAttribution::query()->count());
    }

    public function test_rollback_rechecks_evidence_after_the_reservation_is_locked(): void
    {
        $admin = $this->admin();
        $alpha = $this->agency(6, 'Alpha Travel', 'alpha@example.com');
        $reservation = $this->reservation($alpha->name, 'locked-rollback@example.com', 'ME');
        $this->useBaseline(1, 0);
        $this->assertSame(0, Artisan::call('reservations:prepare-v1-batch3', ['--out' => $this->root]));
        $eligible = $this->root.DIRECTORY_SEPARATOR.'eligible'.DIRECTORY_SEPARATOR.'v1-batch3-eligible-001.csv';
        $approved = $this->root.DIRECTORY_SEPARATOR.'approved.csv';
        $this->assertSame(0, Artisan::call('reservations:approve-v1-batch3-group', $this->approveArgs($admin, $eligible, $approved, HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE)));
        $written = $this->root.DIRECTORY_SEPARATOR.'written.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch3', $this->executeArgs($admin, $approved, $written, 1, 1)));

        $gate = (object) ['on' => true];
        DB::listen(function ($query) use ($gate, $reservation): void {
            if (! $gate->on) {
                return;
            }
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'reservation_attributions') || ! str_contains($sql, 'active_reservation_id')) {
                return;
            }
            $gate->on = false;
            DB::table('reservation_attributions')->where('reservation_id', $reservation->id)->update([
                'evidence' => HistoricalAttributionBatchWriter::EVIDENCE,
            ]);
        });

        $report = $this->root.DIRECTORY_SEPARATOR.'rollback-lock.csv';
        $this->assertSame(1, Artisan::call('reservations:apply-v1-batch3', [
            'csv' => $approved,
            '--report' => $report,
            '--rollback' => true,
            '--execute' => true,
            '--confirm' => HistoricalAttributionBatch3Catalog::CONFIRM_ROLLBACK,
            '--admin-id' => $admin->id,
            '--limit' => 1,
        ]));
        $gate->on = false;
        $this->assertSame('stopped', $this->rollbackReport($report)[$reservation->id]['outcome']);
        $this->assertSame('evidence_mismatch', $this->rollbackReport($report)[$reservation->id]['reason']);
        $this->assertSame($alpha->id, $reservation->fresh()->user_id);
        $audit = DB::table('reservation_attributions')->where('reservation_id', $reservation->id)->first();
        $this->assertSame(HistoricalAttributionBatchWriter::EVIDENCE, $audit->evidence);
        $this->assertSame($reservation->id, (int) $audit->active_reservation_id);
        $this->assertNull($audit->reversal_evidence);
    }

    public function test_closed_batch2_audit_is_rejected_without_an_identity_read(): void
    {
        $admin = $this->admin();
        $alpha = $this->agency(6, 'Alpha Travel', 'alpha@example.com');
        $reservation = $this->reservation($alpha->name, 'closed@example.com', 'ME');
        $this->useBaseline(1, 0);
        $this->assertSame(0, Artisan::call('reservations:prepare-v1-batch3', ['--out' => $this->root]));
        $eligible = $this->root.DIRECTORY_SEPARATOR.'eligible'.DIRECTORY_SEPARATOR.'v1-batch3-eligible-001.csv';
        $approved = $this->root.DIRECTORY_SEPARATOR.'approved.csv';
        $this->assertSame(0, Artisan::call('reservations:approve-v1-batch3-group', $this->approveArgs($admin, $eligible, $approved, HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE)));
        DB::table('reservation_attributions')->insert($this->auditRow(
            $reservation->id,
            $alpha->id,
            HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['A1'],
            null,
        ));

        $vehicleReads = 0;
        DB::listen(function ($query) use (&$vehicleReads): void {
            if (preg_match('/from\s+"?vehicles"?/i', $query->sql) === 1) {
                $vehicleReads++;
            }
        });
        $report = $this->root.DIRECTORY_SEPARATOR.'closed.csv';
        $this->assertSame(0, Artisan::call('reservations:apply-v1-batch3', $this->executeArgs($admin, $approved, $report, 5, 5)));
        $output = Artisan::output();
        $this->assertSame('rejected', $this->report($report)[$reservation->id]['outcome']);
        $this->assertSame('closed_batch2_audit', $this->report($report)[$reservation->id]['reason']);
        $this->assertNull($reservation->fresh()->user_id);
        $this->assertStringContainsString('Attempts: 0'.PHP_EOL, $output);
        $this->assertSame(0, $vehicleReads);
    }

    public function test_reconciliation_rejects_inconsistent_audits_and_leaves_a_partial_batch_pending(): void
    {
        $admin = $this->admin();
        $alpha = $this->agency(6, 'Alpha Travel', 'alpha@example.com');
        $beta = $this->agency(8, 'Beta Travel', 'beta@example.com');
        $approvedRow = $this->reservation($alpha->name, 'approved-reconcile@example.com', 'ME');
        $unapprovedRow = $this->reservation($alpha->name, 'unapproved-reconcile@example.com', 'ME');
        $this->useBaseline(2, 0);
        $this->assertSame(0, Artisan::call('reservations:prepare-v1-batch3', ['--out' => $this->root]));
        $approvedDir = $this->root.DIRECTORY_SEPARATOR.'approved';
        mkdir($approvedDir);
        $approved = $approvedDir.DIRECTORY_SEPARATOR.'only-first.csv';
        $this->craft($approved, [[$approvedRow->id, $alpha->id]], HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE, $admin->id);

        $this->assertSame(0, Artisan::call('reservations:reconcile-v1-batch3', $this->reconcileArgs($approvedDir)));
        $clean = Artisan::output();
        $this->assertStringContainsString('Pending: 1'.PHP_EOL, $clean);
        $this->assertStringContainsString('Problems: 0'.PHP_EOL, $clean);

        DB::table('reservation_attributions')->insert($this->auditRow(
            $unapprovedRow->id,
            $alpha->id,
            HistoricalAttributionBatch3Catalog::EVIDENCE,
            $unapprovedRow->id,
        ));
        DB::table('reservations')->where('id', $unapprovedRow->id)->update(['user_id' => $alpha->id]);
        $this->artisan('reservations:reconcile-v1-batch3', $this->reconcileArgs($approvedDir))
            ->expectsOutputToContain('unapproved_frozen_audit reservation '.$unapprovedRow->id)
            ->assertFailed();
        DB::table('reservation_attributions')->where('reservation_id', $unapprovedRow->id)->delete();
        DB::table('reservations')->where('id', $unapprovedRow->id)->update(['user_id' => null]);

        $duplicate = $approvedDir.DIRECTORY_SEPARATOR.'only-first-copy.csv';
        copy($approved, $duplicate);
        copy(HistoricalAttributionBatch3Catalog::approvalPath($approved), HistoricalAttributionBatch3Catalog::approvalPath($duplicate));
        $this->artisan('reservations:reconcile-v1-batch3', $this->reconcileArgs($approvedDir))
            ->expectsOutputToContain('duplicate_approval reservation '.$approvedRow->id)
            ->assertFailed();
        unlink($duplicate);
        unlink(HistoricalAttributionBatch3Catalog::approvalPath($duplicate));

        $original = (string) file_get_contents($approved);
        file_put_contents($approved, str_replace('approved', 'approvXd', $original));
        $this->artisan('reservations:reconcile-v1-batch3', $this->reconcileArgs($approvedDir))
            ->expectsOutputToContain('approved_file_changed')
            ->assertFailed();
        file_put_contents($approved, $original);

        DB::table('reservation_attributions')->insert($this->auditRow(
            $approvedRow->id,
            $beta->id,
            HistoricalAttributionBatch3Catalog::EVIDENCE,
            $approvedRow->id,
        ));
        DB::table('reservations')->where('id', $approvedRow->id)->update(['user_id' => $beta->id]);
        $this->artisan('reservations:reconcile-v1-batch3', $this->reconcileArgs($approvedDir))
            ->expectsOutputToContain('agency_mismatch reservation '.$approvedRow->id)
            ->assertFailed();
        DB::table('reservation_attributions')->where('reservation_id', $approvedRow->id)->delete();

        DB::table('reservation_attributions')->insert($this->auditRow(
            $approvedRow->id,
            $alpha->id,
            HistoricalAttributionBatch3Catalog::EVIDENCE_REVIEWED,
            $approvedRow->id,
        ));
        DB::table('reservations')->where('id', $approvedRow->id)->update(['user_id' => $alpha->id]);
        $this->artisan('reservations:reconcile-v1-batch3', $this->reconcileArgs($approvedDir))
            ->expectsOutputToContain('evidence_kind_mismatch reservation '.$approvedRow->id)
            ->assertFailed();
        DB::table('reservation_attributions')->where('reservation_id', $approvedRow->id)->delete();

        DB::table('reservation_attributions')->insert($this->auditRow(
            $approvedRow->id,
            $alpha->id,
            HistoricalAttributionBatch3Catalog::EVIDENCE,
            $approvedRow->id,
        ));
        DB::table('reservations')->where('id', $approvedRow->id)->update(['user_id' => $beta->id]);
        $this->artisan('reservations:reconcile-v1-batch3', $this->reconcileArgs($approvedDir))
            ->expectsOutputToContain('user_id_mismatch reservation '.$approvedRow->id)
            ->assertFailed();
        DB::table('reservation_attributions')->where('reservation_id', $approvedRow->id)->delete();
        DB::table('reservations')->where('id', $approvedRow->id)->update(['user_id' => null]);

        $this->assertSame(0, Artisan::call('reservations:reconcile-v1-batch3', $this->reconcileArgs($approvedDir)));
        $restored = Artisan::output();
        $this->assertStringContainsString('Pending: 1'.PHP_EOL, $restored);
        $this->assertStringContainsString('Problems: 0'.PHP_EOL, $restored);
    }

    /**
     * @return array<string, string>
     */
    private function reconcileArgs(string $approvedDir): array
    {
        return [
            '--manifest' => $this->root.DIRECTORY_SEPARATOR.'manifest.json',
            '--approved-dir' => $approvedDir,
        ];
    }

    /**
     * @param  list<array{0: int, 1: int}>  $rows
     */
    private function craft(string $path, array $rows, string $confirm, int $adminId): void
    {
        $review = $confirm === HistoricalAttributionBatch3Catalog::CONFIRM_APPROVE_REVIEW;
        $csvRows = [];
        $pairs = [];
        foreach ($rows as $row) {
            $csvRows[] = [
                'reservation_id' => $row[0],
                'proposed_user_id' => $row[1],
                'disposition' => $review ? HistoricalAttributionBatch3Catalog::DISPOSITION_APPROVED_REVIEW : HistoricalAttributionBatch3Catalog::DISPOSITION_APPROVED,
                'source_class' => HistoricalAttributionBatch3DryRun::CANDIDATE,
                'group_id' => $review ? HistoricalAttributionBatch3Catalog::GROUP_REVIEW : 'b3-craft',
                'hold_reason' => $review ? HistoricalAttributionBatch3Catalog::HOLD_REASON : '',
            ];
            $pairs[] = ['reservation_id' => $row[0], 'proposed_user_id' => $row[1]];
        }
        $hash = HistoricalAttributionBatch3Catalog::writeCsv($path, $csvRows);
        HistoricalAttributionBatch3Catalog::writeJson(HistoricalAttributionBatch3Catalog::approvalPath($path), [
            'source_file' => 'crafted',
            'source_sha256' => $hash,
            'approved_file' => basename($path),
            'approved_sha256' => $hash,
            'group_id' => $csvRows[0]['group_id'],
            'disposition' => $csvRows[0]['disposition'],
            'hold_reason' => $csvRows[0]['hold_reason'],
            'evidence' => $review ? HistoricalAttributionBatch3Catalog::EVIDENCE_REVIEWED : HistoricalAttributionBatch3Catalog::EVIDENCE,
            'confirm' => $confirm,
            'admin_id' => $adminId,
            'approved_at' => '2026-11-16T20:00:00Z',
            'rows' => $pairs,
        ]);
    }

    private function useBaseline(int $class8, int $hold): void
    {
        $this->app->make(Kernel::class)->registerCommand(new class($class8, $hold) extends PrepareV1Batch3Command
        {
            public function __construct(private readonly int $class8, private readonly int $hold)
            {
                parent::__construct();
            }

            protected function expectedClass8(): int
            {
                return $this->class8;
            }

            protected function expectedHold(): int
            {
                return $this->hold;
            }
        });
    }

    /**
     * @return list<string>
     */
    private function watchWrites(): array
    {
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/\b(insert|update|delete|replace)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        return $writes;
    }

    /**
     * @return array<string, mixed>
     */
    private function approveArgs(Admin $admin, string $csv, string $out, string $confirm, array $reservations = []): array
    {
        return [
            'csv' => $csv,
            '--manifest' => $this->root.DIRECTORY_SEPARATOR.'manifest.json',
            '--out' => $out,
            '--confirm' => $confirm,
            '--admin-id' => $admin->id,
            '--reservation' => $reservations,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeArgs(Admin $admin, string $csv, string $report, int $limit, int $attempts, ?string $confirm = null): array
    {
        return [
            'csv' => $csv,
            '--report' => $report,
            '--execute' => true,
            '--confirm' => $confirm ?? HistoricalAttributionBatch3Catalog::CONFIRM_APPLY,
            '--admin-id' => $admin->id,
            '--limit' => $limit,
            '--attempts' => $attempts,
        ];
    }

    /**
     * @return array<int, array{outcome: string, reason: string, evidence: string}>
     */
    private function report(string $path): array
    {
        $handle = fopen($path, 'rb');
        $this->assertNotFalse($handle);
        fgetcsv($handle);
        $parsed = [];
        while (($row = fgetcsv($handle)) !== false) {
            $parsed[(int) $row[0]] = ['outcome' => (string) $row[4], 'reason' => (string) $row[5], 'evidence' => (string) $row[6]];
        }
        fclose($handle);

        return $parsed;
    }

    /**
     * @return array<int, array{outcome: string, reason: string}>
     */
    private function rollbackReport(string $path): array
    {
        $handle = fopen($path, 'rb');
        $this->assertNotFalse($handle);
        fgetcsv($handle);
        $parsed = [];
        while (($row = fgetcsv($handle)) !== false) {
            $parsed[(int) $row[0]] = ['outcome' => (string) $row[1], 'reason' => (string) $row[2]];
        }
        fclose($handle);

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditRow(int $reservationId, int $agencyId, string $evidence, ?int $active): array
    {
        return [
            'reservation_id' => $reservationId,
            'active_reservation_id' => $active,
            'agency_user_id' => $agencyId,
            'previous_user_id' => null,
            'evidence' => $evidence,
            'attributed_at' => now(),
            'attributed_by_admin_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'batch3-admin-'.uniqid(),
            'email' => 'batch3-'.uniqid('', true).'@example.com',
            'password' => 'secret-password-batch',
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    private function agency(int $id, string $name, string $email): User
    {
        return User::factory()->create([
            'id' => $id,
            'name' => $name,
            'email' => $email,
        ]);
    }

    private function reservation(string $name, string $email, string $country): Reservation
    {
        $slot = ListOfTimeSlot::query()->first() ?? ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $reservation = Reservation::query()->create([
            'user_id' => null,
            'user_name' => $name === '' ? 'placeholder' : $name,
            'country' => $country,
            'license_plate' => 'KO1',
            'email' => $email === '' ? 'placeholder@example.com' : $email,
            'vehicle_type_id' => $this->vehicleType()->id,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => '2026-04-01',
            'status' => 'paid',
            'invoice_amount' => '15.00',
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'created_by_admin' => false,
            'merchant_transaction_id' => 'mt-'.uniqid('', true),
        ]);
        DB::table('reservations')->where('id', $reservation->id)->update([
            'user_name' => $name,
            'email' => $email,
            'country' => $country,
            'created_at' => '2026-04-01 10:00:00',
            'updated_at' => '2026-04-01 10:00:00',
        ]);

        return $reservation->fresh();
    }

    private function vehicleType(): VehicleType
    {
        return VehicleType::query()->first() ?? VehicleType::query()->create(['price' => 15]);
    }

    private function removeTree(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path.DIRECTORY_SEPARATOR.$entry);
        }
        rmdir($path);
    }
}
