<?php

namespace Tests\Feature\Reservation;

use App\Console\Commands\DryRunV1Batch3Command;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\ReservationAttribution;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Reservation\HistoricalAttributionBatch2Preflight;
use App\Services\Reservation\HistoricalAttributionBatch2Writer;
use App\Services\Reservation\HistoricalAttributionBatch3DryRun;
use App\Services\Reservation\HistoricalAttributionBatchWriter;
use App\Services\Reservation\HistoricalReservationAttributionService;
use App\Support\ReservationKind;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class HistoricalAttributionBatch3DryRunTest extends TestCase
{
    use RefreshDatabase;

    private string $reportPath;

    /** @var object{listen: bool, writes: list<string>} */
    private object $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reportPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v1-batch3-'.uniqid('', true).'.csv';
        $this->gate = (object) ['listen' => false, 'writes' => []];
        $gate = $this->gate;
        DB::listen(function ($query) use ($gate): void {
            if (! $gate->listen) {
                return;
            }
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query->sql) === 1) {
                $gate->writes[] = $query->sql;
            }
        });
    }

    protected function tearDown(): void
    {
        $this->gate->listen = false;
        if (is_file($this->reportPath)) {
            unlink($this->reportPath);
        }
        if ($this->app !== null) {
            $this->app->make(Kernel::class)->registerCommand(new DryRunV1Batch3Command);
        }
        parent::tearDown();
    }

    public function test_each_v1_reservation_is_classified_once_without_database_writes(): void
    {
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com', 'ME');
        $beta = $this->agency('Beta Travel', 'beta@example.com', 'ME');
        $this->agency('Twin Travel', 'gamma1@example.com', 'DE');
        $this->agency('twin   travel', 'gamma2@example.com', 'DE');
        $left = $this->agency('Left Case', 'Case@Example.com', 'ME');
        $this->agency('Right Case', 'case@example.com', 'ME');
        $this->agency('Box One', 'twinbox@example.com', 'ME');
        $this->agency('Box Two', 'TwinBox@Example.com', 'ME');
        $this->agency('Dot Owner', 'ab@gmail.com', 'ME');
        $this->agency('Plus Owner', 'plus@example.com', 'ME');

        $expected = [];
        $candidate = [];

        $candidate['alpha-nobody'] = $this->reservation('  Alpha   Travel ', 'nobody@example.com', 'DE', 'KOZ1');
        $expected[$candidate['alpha-nobody']->id] = 'name_unique_email_unregistered';
        $candidate['alpha-nobody-again'] = $this->reservation('Alpha Travel', 'nobody@example.com', 'ME', 'OWNEDBYBETA');
        $expected[$candidate['alpha-nobody-again']->id] = 'name_unique_email_unregistered';
        $candidate['beta-other'] = $this->reservation('  beta   travel ', '  Other@Example.com ', 'me', 'B1');
        $expected[$candidate['beta-other']->id] = 'name_unique_email_unregistered';
        $candidate['alpha-shared'] = $this->reservation('Alpha Travel', 'shared-unreg@example.com', 'ME', 'S1');
        $expected[$candidate['alpha-shared']->id] = 'name_unique_email_unregistered';
        $candidate['beta-shared'] = $this->reservation('Beta Travel', 'shared-unreg@example.com', 'ME', 'S2');
        $expected[$candidate['beta-shared']->id] = 'name_unique_email_unregistered';
        $candidate['gmail'] = $this->reservation('Alpha Travel', 'a.b@gmail.com', 'ME', 'G1');
        $expected[$candidate['gmail']->id] = 'name_unique_email_unregistered';
        $candidate['plus'] = $this->reservation('Beta Travel', 'plus+tag@example.com', 'DE', 'P1');
        $expected[$candidate['plus']->id] = 'name_unique_email_unregistered';
        $candidate['gmail-plus'] = $this->reservation('Alpha Travel', 'a.b+c@gmail.com', 'ME', 'G2');
        $expected[$candidate['gmail-plus']->id] = 'name_unique_email_unregistered';

        $otherUser = $this->reservation('Alpha Travel', 'beta@example.com', 'ME', 'C9A');
        $expected[$otherUser->id] = 'name_unique_email_other';
        $includesOwner = $this->reservation('Left Case', 'CASE@example.com', 'ME', 'C9B');
        $expected[$includesOwner->id] = 'name_unique_email_other';
        $severalOthers = $this->reservation('Alpha Travel', 'twinbox@example.com', 'ME', 'C9C');
        $expected[$severalOthers->id] = 'name_unique_email_other';

        $missingEmail = $this->reservation('Alpha Travel', '   ', 'ME', 'C10');
        $expected[$missingEmail->id] = 'name_unique_email_missing';

        $ambiguous = [
            'unregistered' => $this->reservation('Twin Travel', 'nobody-twin@example.com', 'DE', 'A1'),
            'missing' => $this->reservation('Twin Travel', '', 'DE', 'A2'),
            'one_owner' => $this->reservation('Twin Travel', 'alpha@example.com', 'DE', 'A3'),
            'several_owners' => $this->reservation('Twin Travel', 'twinbox@example.com', 'DE', 'A4'),
        ];
        foreach ($ambiguous as $row) {
            $expected[$row->id] = 'name_ambiguous';
        }

        $absent = [
            'unregistered' => $this->reservation('   ', 'ghost-blank@example.com', 'ME', 'N1'),
            'missing' => $this->reservation('', '', 'ME', 'N2'),
            'one_owner' => $this->reservation('   ', 'alpha@example.com', 'ME', 'N3'),
            'several_owners' => $this->reservation('   ', 'twinbox@example.com', 'ME', 'N4'),
        ];
        foreach ($absent as $row) {
            $expected[$row->id] = 'name_absent';
        }

        $unmatched = [
            'unregistered' => $this->reservation('No Such Agency', 'ghost@example.com', 'ME', 'U1'),
            'missing' => $this->reservation('No Such Agency', '', 'ME', 'U2'),
            'one_owner' => $this->reservation('No Such Agency', 'alpha@example.com', 'ME', 'U3'),
            'several_owners' => $this->reservation('No Such Agency', 'TwinBox@Example.com', 'ME', 'U4'),
        ];
        foreach ($unmatched as $row) {
            $expected[$row->id] = 'name_unmatched';
        }

        $identity = $this->reservation('Alpha Travel', 'alpha@example.com', 'ME', 'ID1');
        $expected[$identity->id] = 'identity_email_and_name';

        $attributed = $this->reservation('Alpha Travel', 'stranger@example.com', 'ME', 'AT1');
        DB::table('reservations')->where('id', $attributed->id)->update(['user_id' => $alpha->id]);
        $expected[$attributed->id] = 'already_attributed';

        $attributedDespiteClosed = $this->reservation('Alpha Travel', 'stranger2@example.com', 'ME', 'AT2');
        DB::table('reservations')->where('id', $attributedDespiteClosed->id)->update(['user_id' => $alpha->id]);
        $this->audit($attributedDespiteClosed, HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['B'], null, 'rollback:v1-phase2-batch2:plate_B');
        $expected[$attributedDespiteClosed->id] = 'already_attributed';

        $active = $this->reservation('Alpha Travel', 'active-unreg@example.com', 'ME', 'AC1');
        $this->audit($active, 'fixture', $active->id, null);
        $this->audit($active, 'older-closed', null, 'manual');
        $expected[$active->id] = 'active_audit_without_user';

        $duplicateBatch2 = $this->reservation('Alpha Travel', 'dup-unreg@example.com', 'ME', 'D2');
        $this->audit($duplicateBatch2, HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['R'], null, HistoricalReservationAttributionService::ACCOUNT_DELETED_EVIDENCE);
        $this->audit($duplicateBatch2, HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['A1'], null, HistoricalAttributionBatch2Writer::ROLLBACK_EVIDENCE_BY_CATEGORY['A1']);
        $expected[$duplicateBatch2->id] = 'closed_batch2_audit';

        $mixed = $this->reservation('Alpha Travel', 'mixed-unreg@example.com', 'ME', 'MX');
        $this->audit($mixed, HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['C'], null, HistoricalAttributionBatch2Writer::ROLLBACK_EVIDENCE_BY_CATEGORY['C']);
        $this->audit($mixed, 'manual', null, HistoricalReservationAttributionService::ACCOUNT_DELETED_EVIDENCE);
        $expected[$mixed->id] = 'closed_batch2_audit';

        $deleted = $this->reservation('Alpha Travel', 'deleted-unreg@example.com', 'ME', 'DEL');
        $this->audit($deleted, HistoricalAttributionBatchWriter::EVIDENCE, null, HistoricalReservationAttributionService::ACCOUNT_DELETED_EVIDENCE);
        $expected[$deleted->id] = 'closed_account_deleted';

        $batch1 = $this->reservation('Alpha Travel', 'batch1-unreg@example.com', 'ME', 'B1R');
        $this->audit($batch1, HistoricalAttributionBatchWriter::EVIDENCE, null, HistoricalAttributionBatchWriter::ROLLBACK_EVIDENCE);
        $expected[$batch1->id] = 'closed_batch1_audit';

        $other = $this->reservation('Alpha Travel', 'other-unreg@example.com', 'ME', 'OTH');
        $this->audit($other, 'fixture', null, null);
        $expected[$other->id] = 'other_closed_audit';

        $boundary = $this->reservation('Alpha Travel', 'boundary@example.com', 'ME', 'EDGE', '2026-06-18 00:00:00');
        $native = $this->reservation('Alpha Travel', 'native@example.com', 'ME', 'V2', '2026-07-01 10:00:00');

        $beforeReservations = $this->reservationState();
        $beforeAudits = $this->auditState();
        $this->gate->listen = true;

        $result = app(HistoricalAttributionBatch3DryRun::class)->classify();

        $this->assertSame([], $this->gate->writes);
        $this->assertSame($beforeReservations, $this->reservationState());
        $this->assertSame($beforeAudits, $this->auditState());
        $this->assertSame(10, ReservationAttribution::query()->count());

        $byId = [];
        foreach ($result['rows'] as $row) {
            $byId[$row['reservation_id']] = $row;
        }
        $this->assertSame($expected, array_combine(array_keys($expected), array_map(static fn (int $id): string => $byId[$id]['class'], array_keys($expected))));
        $this->assertCount(count($expected), $result['rows']);
        $this->assertArrayNotHasKey($boundary->id, $byId);
        $this->assertArrayNotHasKey($native->id, $byId);

        $counts = array_count_values($expected);
        foreach (HistoricalAttributionBatch3DryRun::CLASSES as $class) {
            $this->assertSame($counts[$class] ?? 0, $result['counts'][$class], $class);
        }
        $this->assertSame(count($expected), $result['cohort']);
        $this->assertSame($result['cohort'], $result['class_sum']);
        $this->assertSame(2, $result['user_id_set']);
        $this->assertSame($result['cohort'] - 2, $result['user_id_null']);
        $this->assertSame($result['counts']['already_attributed'], $result['user_id_set']);
        $this->assertTrue($result['reconciliation_passed']);

        $this->assertSame($alpha->id, $byId[$candidate['alpha-nobody']->id]['name_owner_user_id']);
        $this->assertSame($alpha->id, $byId[$candidate['alpha-nobody-again']->id]['name_owner_user_id']);
        $this->assertSame($beta->id, $byId[$candidate['beta-other']->id]['name_owner_user_id']);
        $this->assertSame($alpha->id, $byId[$identity->id]['name_owner_user_id']);
        $this->assertSame($alpha->id, $byId[$otherUser->id]['name_owner_user_id']);
        $this->assertSame($left->id, $byId[$includesOwner->id]['name_owner_user_id']);
        $this->assertSame($alpha->id, $byId[$severalOthers->id]['name_owner_user_id']);
        $this->assertSame($alpha->id, $byId[$missingEmail->id]['name_owner_user_id']);
        $this->assertNull($byId[$attributed->id]['name_owner_user_id']);
        $this->assertNull($byId[$active->id]['name_owner_user_id']);
        $this->assertNull($byId[$duplicateBatch2->id]['name_owner_user_id']);
        $this->assertNull($byId[$ambiguous['unregistered']->id]['name_owner_user_id']);
        $this->assertNull($byId[$absent['missing']->id]['name_owner_user_id']);
        $this->assertNull($byId[$unmatched['one_owner']->id]['name_owner_user_id']);

        $this->assertSame([
            'email_unique_other_user' => 1,
            'email_includes_name_owner' => 1,
            'email_multiple_other_users' => 1,
        ], $result['class9_parts']);
        foreach (['name_ambiguous', 'name_absent', 'name_unmatched'] as $class) {
            $this->assertSame([
                'missing' => 1,
                'unregistered' => 1,
                'one_owner' => 1,
                'several_owners' => 1,
            ], $result['email_subcounts'][$class], $class);
        }

        $this->assertSame(2, $result['class8_agencies']);
        $this->assertSame([
            ['user_id' => $alpha->id, 'reservations' => 5, 'distinct_emails' => 4],
            ['user_id' => $beta->id, 'reservations' => 3, 'distinct_emails' => 3],
        ], $result['class8_by_agency']);
        $this->assertSame(8, $result['class8_country_comparable']);
        $this->assertSame(2, $result['class8_country_mismatch']);
        $this->assertSame(0, $result['class8_country_not_comparable']);
        $this->assertSame(2, $result['class8_gmail_dot_alias']);
        $this->assertSame(2, $result['class8_plus_alias']);
        $this->assertSame(1, $result['class8_shared_emails']);
        $this->assertSame(2, $result['class8_shared_email_reservations']);
        $this->assertStringNotContainsString('@', (string) json_encode($result));
        $this->assertStringNotContainsString('license_plate', (string) file_get_contents(app_path('Services/Reservation/HistoricalAttributionBatch3DryRun.php')));

        $this->assertArrayNotHasKey('execute', app(\App\Console\Commands\DryRunV1Batch3Command::class)->getDefinition()->getOptions());
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('reservations:dry-run-v1-batch3'));
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('Cohort: '.$result['cohort'].PHP_EOL, $output);
        $this->assertStringContainsString('user_id set: 2'.PHP_EOL, $output);
        $this->assertStringContainsString('user_id null: '.$result['user_id_null'].PHP_EOL, $output);
        $this->assertStringContainsString('name_unique_email_unregistered: 8'.PHP_EOL, $output);
        $this->assertStringContainsString('Class sum: '.$result['cohort'].PHP_EOL, $output);
        $this->assertStringContainsString('Class 8 distinct proposed agencies: 2'.PHP_EOL, $output);
        $this->assertStringContainsString('Agency '.$alpha->id.': reservations 5, distinct emails 4'.PHP_EOL, $output);
        $this->assertStringContainsString('Agency '.$beta->id.': reservations 3, distinct emails 3'.PHP_EOL, $output);
        $this->assertStringContainsString('Class 9 email owned by one other user: 1'.PHP_EOL, $output);
        $this->assertStringContainsString('name_ambiguous email several_owners: 1'.PHP_EOL, $output);
        $this->assertStringContainsString('Class 8 country mismatch: 2'.PHP_EOL, $output);
        $this->assertStringContainsString('Class 8 gmail-dot alias of a registered email: 2'.PHP_EOL, $output);
        $this->assertStringContainsString('Class 8 plus-tag alias of a registered email: 2'.PHP_EOL, $output);
        $this->assertStringContainsString('Class 8 unregistered emails shared by multiple proposed agencies: 1'.PHP_EOL, $output);
        $this->assertStringContainsString('Class 8 reservations using those shared emails: 2'.PHP_EOL, $output);
        $this->assertStringContainsString('They do not change class membership.'.PHP_EOL, $output);
        $this->assertStringContainsString('Reconciliation: passed'.PHP_EOL, $output);
        $this->assertStringContainsString('not approved for attribution.'.PHP_EOL, $output);
        $this->assertStringNotContainsString('nobody@example.com', $output);
        $this->assertStringNotContainsString('Alpha Travel', $output);
        $this->assertStringNotContainsString('a.b@gmail.com', $output);
        $this->assertStringNotContainsString('shared-unreg@example.com', $output);
        $this->assertSame([], $this->gate->writes);
    }

    public function test_diacritics_do_not_fold_and_gmail_dots_stay_unregistered(): void
    {
        $cacak = $this->agency('Čačak Travel', 'cacak@example.com', 'ME');
        $this->agency('Gmail Dot', 'ab@gmail.com', 'ME');

        $folded = $this->reservation("  čačak\u{00A0}travel  ", 'visitor@example.com', 'ME', 'P1');
        $ascii = $this->reservation('Cacak Travel', 'other-visitor@example.com', 'ME', 'P2');
        $dotted = $this->reservation('Čačak Travel', 'a.b@gmail.com', 'ME', 'P3');

        $this->gate->listen = true;
        $result = app(HistoricalAttributionBatch3DryRun::class)->classify();
        $this->assertSame([], $this->gate->writes);

        $byId = [];
        foreach ($result['rows'] as $row) {
            $byId[$row['reservation_id']] = $row;
        }
        $this->assertSame('name_unique_email_unregistered', $byId[$folded->id]['class']);
        $this->assertSame($cacak->id, $byId[$folded->id]['name_owner_user_id']);
        $this->assertSame('name_unmatched', $byId[$ascii->id]['class']);
        $this->assertNull($byId[$ascii->id]['name_owner_user_id']);
        $this->assertSame('name_unique_email_unregistered', $byId[$dotted->id]['class']);
        $this->assertSame($cacak->id, $byId[$dotted->id]['name_owner_user_id']);
        $this->assertSame(3, $result['cohort']);
        $this->assertSame(1, $result['class8_gmail_dot_alias']);
        $this->assertTrue($result['reconciliation_passed']);
    }

    public function test_report_csv_is_optional_and_cannot_land_inside_the_repository(): void
    {
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com', 'ME');
        $candidate = $this->reservation('Alpha Travel', 'visitor@example.com', 'ME', 'P1');
        $attributed = $this->reservation('Alpha Travel', 'alpha@example.com', 'ME', 'P2');
        DB::table('reservations')->where('id', $attributed->id)->update(['user_id' => $alpha->id]);

        $inside = base_path('v1-batch3-should-not-exist.csv');
        $this->artisan('reservations:dry-run-v1-batch3', ['--report' => $inside])
            ->expectsOutputToContain('outside the repository')
            ->assertFailed();
        $this->assertFileDoesNotExist($inside);

        file_put_contents($this->reportPath, "keep\n");
        $this->artisan('reservations:dry-run-v1-batch3', ['--report' => $this->reportPath])
            ->expectsOutputToContain('already exists and was not overwritten.')
            ->assertFailed();
        $this->assertSame("keep\n", file_get_contents($this->reportPath));
        unlink($this->reportPath);

        $missing = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v1-batch3-missing-'.uniqid('', true).DIRECTORY_SEPARATOR.'out.csv';
        $this->artisan('reservations:dry-run-v1-batch3', ['--report' => $missing])
            ->expectsOutputToContain('existing directory')
            ->assertFailed();
        $this->assertFileDoesNotExist($missing);

        $this->artisan('reservations:dry-run-v1-batch3', ['--report' => $this->reportPath])
            ->expectsOutputToContain('not an attribution file.')
            ->assertSuccessful();

        $handle = fopen($this->reportPath, 'rb');
        $this->assertNotFalse($handle);
        $this->assertSame(['reservation_id', 'class', 'name_owner_user_id'], fgetcsv($handle));
        $parsed = [];
        while (($row = fgetcsv($handle)) !== false) {
            $parsed[(int) $row[0]] = ['class' => $row[1], 'owner' => $row[2]];
        }
        fclose($handle);
        $this->assertSame([
            $candidate->id => ['class' => 'name_unique_email_unregistered', 'owner' => (string) $alpha->id],
            $attributed->id => ['class' => 'already_attributed', 'owner' => ''],
        ], $parsed);
        $contents = (string) file_get_contents($this->reportPath);
        $this->assertStringNotContainsString('visitor@example.com', $contents);
        $this->assertStringNotContainsString('Alpha Travel', $contents);
    }

    public function test_failed_reconciliation_prints_results_and_writes_no_report(): void
    {
        $this->registerCommand(new class extends DryRunV1Batch3Command
        {
            protected function classification(\App\Services\Reservation\HistoricalAttributionBatch3DryRun $dryRun): array
            {
                $result = parent::classification($dryRun);
                $result['reconciliation_passed'] = false;

                return $result;
            }
        });

        $this->artisan('reservations:dry-run-v1-batch3', ['--report' => $this->reportPath])
            ->expectsOutputToContain('Cohort: 0')
            ->expectsOutputToContain('Reconciliation: failed')
            ->expectsOutputToContain('No report file was written.')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->reportPath);
    }

    public function test_incomplete_report_is_removed(): void
    {
        $this->registerCommand(new class extends DryRunV1Batch3Command
        {
            protected function writeReportRows($handle, array $rows): void
            {
                fputcsv($handle, ['reservation_id', 'class', 'name_owner_user_id']);

                throw new \RuntimeException('The report file could not be written.');
            }
        });

        $this->artisan('reservations:dry-run-v1-batch3', ['--report' => $this->reportPath])
            ->expectsOutputToContain('Reconciliation: passed')
            ->expectsOutputToContain('The report file could not be written.')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->reportPath);
    }

    private function registerCommand(DryRunV1Batch3Command $command): void
    {
        $this->app->make(Kernel::class)->registerCommand($command);
    }

    public function test_missing_audit_table_fails_without_migrating(): void
    {
        Schema::drop('reservation_attributions');

        try {
            $this->artisan('reservations:dry-run-v1-batch3')
                ->expectsOutputToContain('does not migrate')
                ->assertFailed();

            $this->assertFalse(Schema::hasTable('reservation_attributions'));
        } finally {
            if (! Schema::hasTable('reservation_attributions')) {
                Schema::create('reservation_attributions', function ($table): void {
                    $table->id();
                    $table->unsignedBigInteger('reservation_id');
                    $table->unsignedBigInteger('active_reservation_id')->nullable()->unique();
                    $table->unsignedBigInteger('agency_user_id');
                    $table->unsignedBigInteger('previous_user_id')->nullable();
                    $table->string('evidence', 500);
                    $table->timestamp('attributed_at');
                    $table->unsignedBigInteger('attributed_by_admin_id')->nullable();
                    $table->timestamp('reversed_at')->nullable();
                    $table->unsignedBigInteger('reversed_by_admin_id')->nullable();
                    $table->string('reversal_evidence', 500)->nullable();
                    $table->timestamps();
                    $table->index('reservation_id');
                    $table->index('agency_user_id');
                });
            }
        }
    }

    private function agency(string $name, string $email, string $country): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'country' => $country,
        ]);
    }

    private function reservation(string $name, string $email, string $country, string $plate, string $createdAt = '2026-04-01 10:00:00'): Reservation
    {
        $slot = ListOfTimeSlot::query()->first() ?? ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $reservation = Reservation::query()->create([
            'user_id' => null,
            'user_name' => $name === '' ? 'placeholder' : $name,
            'country' => $country,
            'license_plate' => $plate,
            'email' => $email === '' ? 'placeholder@example.com' : $email,
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
            'user_name' => $name,
            'email' => $email,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $reservation->fresh();
    }

    private function audit(Reservation $reservation, string $evidence, ?int $activeReservationId, ?string $reversal): void
    {
        ReservationAttribution::query()->create([
            'reservation_id' => $reservation->id,
            'active_reservation_id' => $activeReservationId,
            'agency_user_id' => User::query()->value('id'),
            'previous_user_id' => null,
            'evidence' => $evidence,
            'attributed_at' => '2026-11-01 00:00:00',
            'attributed_by_admin_id' => null,
            'reversed_at' => $activeReservationId === null ? '2026-11-02 00:00:00' : null,
            'reversed_by_admin_id' => null,
            'reversal_evidence' => $reversal,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reservationState(): array
    {
        return DB::table('reservations')
            ->orderBy('id')
            ->get(['id', 'user_id', 'email', 'user_name', 'country', 'license_plate', 'created_at'])
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditState(): array
    {
        return DB::table('reservation_attributions')
            ->orderBy('id')
            ->get(['id', 'reservation_id', 'active_reservation_id', 'evidence', 'reversal_evidence', 'agency_user_id'])
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    private function vehicleType(): VehicleType
    {
        return VehicleType::query()->first() ?? VehicleType::query()->create(['price' => 15]);
    }
}
