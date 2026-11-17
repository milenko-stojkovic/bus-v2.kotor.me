<?php

namespace Tests\Feature\Reservation;

use App\Console\Commands\DiagnoseV1Batch4Command;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\Reservation\HistoricalAttributionBatch2Preflight;
use App\Services\Reservation\HistoricalAttributionBatch3Catalog;
use App\Services\Reservation\HistoricalAttributionBatch4Diagnostics;
use App\Services\Reservation\HistoricalAttributionBatchWriter;
use App\Support\ReservationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class HistoricalAttributionBatch4DiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private string $reportPath;

    /** @var object{listen: bool, writes: list<string>} */
    private object $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reportPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v1-batch4-diag-'.uniqid('', true).'.csv';
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
        parent::tearDown();
    }

    public function test_cohort_boundary_and_null_user_id_do_not_define_eligibility(): void
    {
        $agency = $this->agency('Alpha Travel', 'alpha@example.com');
        $historical = $this->reservation('Other Desk', 'guest@example.com', 'KO10', '2026-06-17 23:59:59');
        $boundary = $this->reservation('Other Desk', 'later@example.com', 'KO11', '2026-06-18 00:00:00');
        $attributed = $this->reservation('Other Desk', 'kept@example.com', 'KO12', '2026-04-01 10:00:00', $agency->id);
        $undated = $this->reservation('Other Desk', 'undated@example.com', 'KO13', '2026-04-01 10:00:00');
        DB::table('reservations')->where('id', $undated->id)->update(['created_at' => null]);

        $this->gate->listen = true;
        $result = app(HistoricalAttributionBatch4Diagnostics::class)->collect();
        $this->assertSame([], $this->gate->writes);

        $this->assertSame(2, $result['live']['historical']);
        $this->assertSame(1, $result['live']['attributed']);
        $this->assertSame(1, $result['live']['unattributed']);
        $this->assertSame(1, $result['reference']['post_cutoff']);
        $this->assertSame(1, $result['reference']['post_cutoff_guest']);
        $this->assertSame(0, $result['reference']['post_cutoff_user_linked']);
        $this->assertSame(1, $result['reference']['created_at_null']);
        $this->assertSame(1, $result['discovery_population']);
        $this->assertSame(1, $result['categories']['b4e']['reservations']);
        $this->assertSame($historical->id, $result['samples']['b4e'][0]['reservation_id']);
        $this->assertNotContains($boundary->id, array_column($result['samples']['b4e'], 'reservation_id'));
        $this->assertNotContains($attributed->id, array_column($result['samples']['b4e'], 'reservation_id'));
        $this->assertFalse($result['baseline']['matches']);
        $this->assertStringContainsString('historical: live 2, verified 21342', implode("\n", $result['baseline']['deviations']));
        $this->assertStringContainsString('REPEATABLE READ', (string) file_get_contents(app_path('Services/Reservation/HistoricalAttributionBatch4Diagnostics.php')));
        $this->assertStringContainsString('SQLite tests run on one connection', (string) file_get_contents(app_path('Services/Reservation/HistoricalAttributionBatch4Diagnostics.php')));
    }

    public function test_active_and_closed_audits_are_excluded_and_reported(): void
    {
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $beta = $this->agency('Beta Travel', 'beta@example.com');
        $active = $this->reservation('Other Desk', 'active@example.com', 'KO20', '2026-04-01 10:00:00');
        $closed = $this->reservation('Other Desk', 'closed@example.com', 'KO21', '2026-04-01 10:00:00');
        $bare = $this->reservation('Other Desk', 'bare@example.com', 'KO22', '2026-04-01 10:00:00', $alpha->id);
        $disagreement = $this->reservation('Other Desk', 'disagree@example.com', 'KO23', '2026-04-01 10:00:00', $beta->id);
        $later = $this->reservation('Other Desk', 'later-audit@example.com', 'KO24', '2026-06-18 00:00:01', $alpha->id);
        DB::table('reservation_attributions')->insert($this->auditRow($active->id, $alpha->id, HistoricalAttributionBatchWriter::EVIDENCE, $active->id));
        DB::table('reservation_attributions')->insert($this->auditRow($closed->id, $alpha->id, HistoricalAttributionBatchWriter::EVIDENCE, null));
        DB::table('reservation_attributions')->insert($this->auditRow($disagreement->id, $alpha->id, HistoricalAttributionBatchWriter::EVIDENCE, $disagreement->id));
        DB::table('reservation_attributions')->insert($this->auditRow($later->id, $alpha->id, HistoricalAttributionBatchWriter::EVIDENCE, $later->id));
        DB::table('reservation_attributions')->insert($this->auditRow(999999, $alpha->id, HistoricalAttributionBatchWriter::EVIDENCE, 999999));

        $result = app(HistoricalAttributionBatch4Diagnostics::class)->collect();

        $this->assertSame(0, $result['discovery_population']);
        $this->assertSame(2, $result['excluded_from_discovery']);
        $this->assertSame(2, $result['live']['batch1_active']);
        $this->assertSame(1, $result['inconsistencies']['unattributed_with_active_audit']);
        $this->assertSame(1, $result['inconsistencies']['unattributed_with_closed_audit']);
        $this->assertSame(1, $result['inconsistencies']['attributed_without_active_audit']);
        $this->assertSame(1, $result['inconsistencies']['user_id_disagrees_with_active_audit']);
        $this->assertSame([$active->id], $result['inconsistencies']['ids']['unattributed_with_active_audit']);
        $this->assertSame([$closed->id], $result['inconsistencies']['ids']['unattributed_with_closed_audit']);
        $this->assertSame([$bare->id], $result['inconsistencies']['ids']['attributed_without_active_audit']);
        $this->assertContains($later->id, $result['inconsistencies']['ids']['active_audit_outside_cohort']);
        $this->assertContains(999999, $result['inconsistencies']['ids']['active_audit_outside_cohort']);
        $this->assertSame(0, $result['overlap']['any_of_abcd']);
        $this->assertSame(0, $result['categories']['b4e']['reservations']);
    }

    public function test_normalization_shared_identities_and_ambiguous_names_do_not_invent_a_candidate(): void
    {
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $this->agency('Travel Alpha', 'travel-alpha@example.com');
        $this->agency('Shared Person', 'Shared@Example.com');
        $this->agency('Shared Other', 'shared@example.com');
        $cased = $this->reservation('Different Desk', '  Alpha@Example.com ', 'KO30', '2026-04-01 10:00:00');
        $exact = $this->reservation('  Alpha   Travel ', 'ALPHA@example.com', 'KO31', '2026-04-01 10:00:00');
        $sharedEmail = $this->reservation('Different Desk', 'shared@example.com', 'KO32', '2026-04-01 10:00:00');
        $ambiguous = $this->reservation('Alpha-Travel', 'alias@example.com', 'KO33', '2026-04-01 10:00:00');
        $invalid = $this->reservation('Different Desk', 'not-an-email', 'KO34', '2026-04-01 10:00:00');
        $sharedPlate = $this->reservation('Alpha Travel', '', 'ko-40', '2026-04-01 10:00:00');
        $this->vehicle($alpha->id, 'KO 40');
        $this->vehicle($this->agency('Plate Two', 'plate-two@example.com')->id, 'ko40');

        $result = app(HistoricalAttributionBatch4Diagnostics::class)->collect();

        $this->assertSame([$cased->id], array_column($result['samples']['b4a'], 'reservation_id'));
        $this->assertSame($alpha->id, $result['samples']['b4a'][0]['proposed_user_id']);
        $this->assertSame('a***@example.com', $result['samples']['b4a'][0]['masked_email']);
        $this->assertSame(0, $result['categories']['b4c']['reservations']);
        $this->assertContains($exact->id, array_column($result['samples']['b4e'], 'reservation_id'));
        $exactSample = $this->sampleFor($result, 'b4e', $exact->id);
        $this->assertContains('registered_email_exact_name', $exactSample['ambiguity']);
        $sharedSample = $this->sampleFor($result, 'b4e', $sharedEmail->id);
        $this->assertContains('email_multi_user', $sharedSample['conflicting']);
        $aliasSample = $this->sampleFor($result, 'b4e', $ambiguous->id);
        $this->assertContains('alias_ambiguous', $aliasSample['conflicting']);
        $this->assertNotContains('b4c', $aliasSample['signals']);
        $this->assertSame(1, $result['quality']['email_invalid_shape']);
        $this->assertContains($invalid->id, array_column($result['samples']['b4e'], 'reservation_id'));
        $this->assertGreaterThanOrEqual(1, $result['quality']['email_multi_user']);
        $this->assertGreaterThanOrEqual(1, $result['quality']['alias_multi_user']);
        $this->assertGreaterThanOrEqual(1, $result['quality']['plate_multi_registry_user']);
        $this->assertSame(0, $result['categories']['b4d']['reservations']);
        $this->assertNotContains($sharedPlate->id, array_column($result['samples']['b4d'], 'reservation_id'));
    }

    public function test_categories_overlap_without_using_a_heuristic_as_proof(): void
    {
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $beta = $this->agency('Beta Travel', 'beta@example.com');
        $batch1 = $this->reservation('Alpha Travel', 'unreg@example.com', 'KO9', '2026-03-01 10:00:00', $alpha->id);
        $batch2 = $this->reservation('Alpha Travel', 'label@example.com', 'KO8', '2026-03-02 10:00:00', $alpha->id);
        $batch3 = $this->reservation('Alpha Travel', 'weak@example.com', 'KO7', '2026-03-03 10:00:00', $alpha->id);
        DB::table('reservation_attributions')->insert($this->auditRow($batch1->id, $alpha->id, HistoricalAttributionBatchWriter::EVIDENCE, $batch1->id));
        DB::table('reservation_attributions')->insert($this->auditRow(
            $batch2->id,
            $alpha->id,
            HistoricalAttributionBatch2Preflight::EVIDENCE_BY_CATEGORY['B'],
            $batch2->id,
        ));
        DB::table('reservation_attributions')->insert($this->auditRow($batch3->id, $alpha->id, HistoricalAttributionBatch3Catalog::EVIDENCE, $batch3->id));

        $overlap = $this->reservation('Travel Alpha', 'alpha@example.com', 'ZZ9', '2026-04-01 10:00:00');
        $both = $this->reservation('Alpha Travel', 'unreg@example.com', 'KO9', '2026-04-02 10:00:00');
        $circular = $this->reservation('Alpha Travel', 'weak@example.com', 'KO7', '2026-04-03 10:00:00');
        $conflict = $this->reservation('Alpha Travel', 'beta@example.com', 'KO9', '2026-04-04 10:00:00');
        $labelOnly = $this->reservation('Alpha Travel', 'different@example.com', 'KO8', '2026-04-05 10:00:00');
        $missing = $this->reservation('', '', '', '2026-04-06 10:00:00');
        DB::table('reservations')->where('id', $missing->id)->update(['status' => 'free']);
        $postCutoff = $this->reservation('Alpha Travel', 'later-only@example.com', 'KO70', '2026-07-01 10:00:00', $alpha->id);
        $uncertain = $this->reservation('Other Desk', 'later-only@example.com', 'KO71', '2026-04-07 10:00:00');

        $result = app(HistoricalAttributionBatch4Diagnostics::class)->collect();

        $this->assertSame(2, $result['categories']['b4a']['reservations']);
        $this->assertSame(2, $result['categories']['b4a']['distinct_agencies']);
        $this->assertSame(1, $result['categories']['b4b']['reservations']);
        $this->assertSame(1, $result['categories']['b4c']['reservations']);
        $this->assertSame(3, $result['categories']['b4d']['reservations']);
        $this->assertSame(3, $result['categories']['b4e']['reservations']);
        $this->assertSame(1, $result['overlap']['pairs']['b4a_b4c']);
        $this->assertSame(1, $result['overlap']['pairs']['b4a_b4d']);
        $this->assertSame(1, $result['overlap']['pairs']['b4b_b4d']);
        $this->assertSame(0, $result['overlap']['pairs']['b4a_b4b']);
        $this->assertSame(0, $result['overlap']['three_or_more']);
        $this->assertSame(4, $result['overlap']['any_of_abcd']);
        $this->assertSame(1, $result['overlap']['multiple_proposed_agencies']);
        $this->assertSame(1, $result['overlap']['contradictory']);
        $this->assertSame(3, $result['overlap']['no_credible_candidate']);
        $this->assertSame(7, $result['discovery_population']);
        $this->assertSame(1, $result['weak']['batch3_email_only']);
        $this->assertSame(1, $result['weak']['uncertain_post_cutoff_email_only']);
        $this->assertSame(0, $result['categories']['b4b']['reservations'] - 1);

        $overlapSample = $this->sampleFor($result, 'b4a', $overlap->id);
        $this->assertSame(['b4a', 'b4c'], $overlapSample['signals']);
        $this->assertSame($alpha->id, $overlapSample['proposed_user_id']);
        $this->assertContains('users.email', $overlapSample['supporting']);
        $this->assertContains('exploratory_alias', $overlapSample['anchor_provenance']);
        $this->assertSame(1, $overlapSample['independent_references']);

        $bothSample = $this->sampleFor($result, 'b4b', $both->id);
        $this->assertSame(['b4b', 'b4d'], $bothSample['signals']);
        $this->assertSame($alpha->id, $bothSample['proposed_user_id']);
        $this->assertContains('batch1', $bothSample['anchor_provenance']);
        $this->assertSame(1, $bothSample['independent_references']);
        $this->assertNotContains('batch3', $bothSample['anchor_provenance']);

        $circularSample = $this->sampleFor($result, 'b4e', $circular->id);
        $this->assertSame(['b4e'], $circularSample['signals']);
        $this->assertNull($circularSample['proposed_user_id']);
        $this->assertSame(0, $circularSample['independent_references']);
        $this->assertContains('batch3_email_seen', $circularSample['ambiguity']);
        $this->assertNotContains($circular->id, array_column($result['samples']['b4d'], 'reservation_id'));

        $conflictSample = $this->sampleFor($result, 'b4a', $conflict->id);
        $this->assertEqualsCanonicalizing([$alpha->id, $beta->id], $conflictSample['proposed_user_ids']);
        $this->assertNull($conflictSample['proposed_user_id']);
        $this->assertContains('proposals_disagree', $conflictSample['conflicting']);

        $labelSample = $this->sampleFor($result, 'b4d', $labelOnly->id);
        $this->assertSame(['b4d'], $labelSample['signals']);
        $this->assertContains('batch2_plate_label', $labelSample['anchor_provenance']);
        $this->assertContains('batch2_plate_label_only', $labelSample['ambiguity']);

        $missingSample = $this->sampleFor($result, 'b4e', $missing->id);
        $this->assertContains('email_missing', $missingSample['ambiguity']);
        $this->assertContains('name_missing', $missingSample['ambiguity']);
        $this->assertContains('plate_missing', $missingSample['ambiguity']);
        $uncertainSample = $this->sampleFor($result, 'b4e', $uncertain->id);
        $this->assertContains('uncertain_post_cutoff_seen', $uncertainSample['ambiguity']);
        $this->assertSame(1, $result['reference']['post_cutoff_user_linked']);
        $this->assertSame('free', array_key_first(array_intersect_key($result['quality']['status'], ['free' => true])) ? 'free' : '');
        $this->assertSame(1, $result['quality']['status']['free']);
        $this->assertSame(6, $result['quality']['status']['paid']);
        $this->assertSame(1, $result['quality']['email_empty']);
        $this->assertSame(6, $result['quality']['email_nonempty']);
        $this->assertSame(1, $result['quality']['name_empty']);
        $this->assertSame(1, $result['quality']['plate_empty']);
        $this->assertSame(1, $result['quality']['repeated_plates']['values']);
        $this->assertSame(2, $result['quality']['repeated_plates']['largest']);
        $this->assertGreaterThan(1, $result['quality']['repeated_names']['largest']);
        $this->assertNotNull($postCutoff->id);
        $this->assertSame(1, $result['live']['batch1_active']);
        $this->assertSame(1, $result['live']['batch2_active']);
        $this->assertSame(1, $result['live']['batch3_active']);
        $this->assertSame(1, $result['live']['batch3_regular_active']);
        $this->assertSame(0, $result['live']['batch3_reviewed_active']);
    }

    public function test_an_alias_needs_independent_support_and_a_reviewed_name_is_not_an_anchor(): void
    {
        $alpha = $this->agency('Alpha Travel d.o.o.', 'alpha@example.com');
        $this->vehicle($alpha->id, 'PG100');
        $supported = $this->reservation('Travel Alpha', '', 'PG100', '2026-04-01 10:00:00');
        $unsupported = $this->reservation('Travel Alpha', '', 'PG200', '2026-04-02 10:00:00');
        $anchor = $this->reservation('Alpha Travel d.o.o.', 'reviewed@example.com', 'PG300', '2026-03-01 10:00:00', $alpha->id);
        $reviewed = $this->reservation('Other Desk', 'reviewed@example.com', 'PG400', '2026-04-03 10:00:00');
        DB::table('reservation_attributions')->insert($this->auditRow(
            $anchor->id,
            $alpha->id,
            HistoricalAttributionBatch3Catalog::EVIDENCE_REVIEWED,
            $anchor->id,
        ));

        $result = app(HistoricalAttributionBatch4Diagnostics::class)->collect();

        $this->assertSame([$supported->id], array_column($result['samples']['b4c'], 'reservation_id'));
        $this->assertSame($alpha->id, $result['samples']['b4c'][0]['proposed_user_id']);
        $this->assertContains('vehicle_registry_plate', $result['samples']['b4c'][0]['supporting']);
        $this->assertContains('vehicles', $result['samples']['b4c'][0]['anchor_provenance']);
        $this->assertSame(1, $result['weak']['alias_without_support']);
        $this->assertContains($unsupported->id, array_column($result['samples']['b4e'], 'reservation_id'));
        $reviewedSample = $this->sampleFor($result, 'b4e', $reviewed->id);
        $this->assertContains('reviewed_name_email_seen', $reviewedSample['ambiguity']);
        $this->assertSame(0, $reviewedSample['independent_references']);
        $this->assertSame(0, $result['categories']['b4b']['reservations']);
        $this->assertSame(1, $result['live']['batch3_reviewed_active']);
        $this->assertSame(1, $result['weak']['reviewed_email_only']);
    }

    public function test_command_is_read_only_and_refuses_a_report_inside_the_repository(): void
    {
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $this->reservation('Different Desk', 'alpha@example.com', 'KO1', '2026-04-01 10:00:00');
        $before = $this->storedRows();
        $this->gate->listen = true;

        $this->assertSame(0, Artisan::call('reservations:diagnose-v1-batch4', ['--report' => $this->reportPath]));
        $output = Artisan::output();
        $this->assertStringContainsString('read-only', $output);
        $this->assertStringContainsString('B4-A reservations: 1', $output);
        $this->assertStringContainsString('Do not add them together.', $output);
        $this->assertStringContainsString('a***@example.com', $output);
        $this->assertStringNotContainsString('alpha@example.com', $output);
        $this->assertStringNotContainsString('Alpha Travel', $output);
        $this->assertSame([], $this->gate->writes);
        $this->assertSame($before, $this->storedRows());
        $this->assertStringNotContainsString('alpha@example.com', (string) file_get_contents($this->reportPath));
        $this->assertStringContainsString('a***@example.com', (string) file_get_contents($this->reportPath));

        $inside = base_path('v1-batch4-diag-should-not-exist.csv');
        $this->artisan('reservations:diagnose-v1-batch4', ['--report' => $inside])
            ->expectsOutputToContain('public document root')
            ->assertFailed();
        $this->assertFileDoesNotExist($inside);
        $this->assertArrayNotHasKey('execute', app(DiagnoseV1Batch4Command::class)->getDefinition()->getOptions());
        $this->assertStringNotContainsString('reservations:diagnose-v1-batch4', (string) file_get_contents(base_path('routes/console.php')));
        $this->assertNotNull($alpha->id);
    }

    public function test_missing_audit_table_fails_without_migrating(): void
    {
        Schema::drop('reservation_attributions');

        try {
            $this->artisan('reservations:diagnose-v1-batch4')
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

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function sampleFor(array $result, string $signal, int $reservationId): array
    {
        foreach ($result['samples'][$signal] as $sample) {
            if ($sample['reservation_id'] === $reservationId) {
                return $sample;
            }
        }

        $this->fail('Sample '.$signal.' did not include reservation '.$reservationId);
    }

    private function agency(string $name, string $email): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
        ]);
    }

    private function vehicle(int $userId, string $plate): Vehicle
    {
        return Vehicle::query()->create([
            'user_id' => $userId,
            'license_plate' => $plate,
            'vehicle_type_id' => $this->vehicleType()->id,
            'status' => Vehicle::STATUS_ACTIVE,
        ]);
    }

    private function reservation(string $name, string $email, string $plate, string $createdAt, ?int $userId = null): Reservation
    {
        $slot = ListOfTimeSlot::query()->first() ?? ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $reservation = Reservation::query()->create([
            'user_id' => null,
            'user_name' => $name === '' ? 'placeholder' : $name,
            'country' => 'ME',
            'license_plate' => $plate === '' ? 'PLACE' : $plate,
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
            'user_id' => $userId,
            'user_name' => $name,
            'email' => $email,
            'license_plate' => $plate,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $reservation->fresh();
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
            'attributed_at' => '2026-11-01 10:00:00',
            'attributed_by_admin_id' => null,
            'created_at' => '2026-11-01 10:00:00',
            'updated_at' => '2026-11-01 10:00:00',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedRows(): array
    {
        return DB::table('reservations')
            ->orderBy('id')
            ->get(['id', 'user_id', 'email', 'user_name', 'license_plate'])
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    private function vehicleType(): VehicleType
    {
        return VehicleType::query()->first() ?? VehicleType::query()->create(['price' => 15]);
    }
}
