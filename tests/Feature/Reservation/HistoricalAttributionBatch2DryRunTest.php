<?php

namespace Tests\Feature\Reservation;

use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\ReservationAttribution;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Support\ReservationKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class HistoricalAttributionBatch2DryRunTest extends TestCase
{
    use RefreshDatabase;

    private string $candidatesPath;

    /** @var list<string> */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->candidatesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v1-batch2-'.uniqid('', true).'.csv';
        $this->writes = [];
    }

    protected function tearDown(): void
    {
        if (is_file($this->candidatesPath)) {
            unlink($this->candidatesPath);
        }
        parent::tearDown();
    }

    public function test_each_open_v1_reservation_is_classified_once_without_database_writes(): void
    {
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com');
        $beta = $this->agency('Beta Travel', 'beta@example.com');
        $gamma = $this->agency('Gamma Travel', 'gamma@example.com');
        $delta = $this->agency('Delta Travel', 'delta@example.com');
        $this->vehicle($alpha, 'KOZ1', Vehicle::STATUS_ACTIVE);
        $this->vehicle($beta, 'SH-ARE 01', Vehicle::STATUS_ACTIVE);
        $this->vehicle($gamma, 'SHARE01', Vehicle::STATUS_REMOVED);
        $this->vehicle($delta, 'NULLSHARE', Vehicle::STATUS_ACTIVE);
        $this->vehicle(null, 'NULLSHARE', Vehicle::STATUS_REMOVED);
        $this->vehicle($beta, 'TWO1', Vehicle::STATUS_ACTIVE);
        $this->vehicle($gamma, 'TWO1', Vehicle::STATUS_ACTIVE);
        $this->vehicle(null, 'GUESTONLY', Vehicle::STATUS_ACTIVE);
        $this->vehicle($beta, 'OWNEDBYBETA', Vehicle::STATUS_REMOVED);
        $this->vehicle($alpha, 'OTHERONLY', 'parked');

        $rows = [
            'R' => $this->reservation($alpha, 'Alpha Travel', 'Alpha@Example.com', 'KOZ1'),
            'A1' => $this->reservation($beta, 'Beta Travel', 'beta@example.com', 'share01'),
            'A1null' => $this->reservation($delta, 'Delta Travel', 'delta@example.com', 'NULLSHARE'),
            'A2' => $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'TWO1'),
            'B' => $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'MISSING1'),
            'Bnull' => $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'GUESTONLY'),
            'C' => $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'OWNEDBYBETA'),
            'D' => $this->reservation($alpha, 'Beta Travel', 'alpha@example.com', 'KOZ1'),
            'E' => $this->reservation($gamma, 'Gamma Travel', 'nobody@example.com', 'SHARE01'),
            'F' => $this->ambiguousEmailReservation(),
            'ignored_status' => $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'OTHERONLY'),
        ];

        $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1', '2026-07-01 10:00:00');
        $attributed = $this->reservation($alpha, 'Alpha Travel', 'alpha@example.com', 'KOZ1');
        DB::table('reservations')->where('id', $attributed->id)->update(['user_id' => $alpha->id]);

        $audited = $this->reservation($beta, 'Beta Travel', 'beta@example.com', 'OWNEDBYBETA');
        DB::table('reservation_attributions')->insert([
            'reservation_id' => $audited->id,
            'active_reservation_id' => $audited->id,
            'agency_user_id' => $beta->id,
            'previous_user_id' => null,
            'evidence' => 'fixture',
            'attributed_at' => now(),
            'attributed_by_admin_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->listenForWrites();

        $result = app(\App\Services\Reservation\HistoricalAttributionBatch2DryRun::class)->classify();
        $candidates = $result['candidates'];
        unset($result['candidates']);
        $this->assertSame([
            'scanned' => 12,
            'excluded_active_audit' => 1,
            'eligible' => 11,
            'counts' => ['R' => 1, 'A1' => 2, 'A2' => 1, 'B' => 3, 'C' => 1, 'D' => 1, 'E' => 1, 'F' => 1],
            'category_sum' => 11,
            'identity_candidates' => 8,
            'distinct_agencies' => 3,
            'agencies_by_category' => ['R' => 1, 'A1' => 2, 'A2' => 1, 'B' => 1, 'C' => 1],
            'a1_other_agency' => 1,
            'a1_extra_null_owner' => 1,
        ], $result);
        $this->assertSame([
            $rows['R']->id => 'R',
            $rows['A1']->id => 'A1',
            $rows['A1null']->id => 'A1',
            $rows['A2']->id => 'A2',
            $rows['B']->id => 'B',
            $rows['Bnull']->id => 'B',
            $rows['C']->id => 'C',
            $rows['ignored_status']->id => 'B',
        ], array_column($candidates, 'plate_category', 'reservation_id'));

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('reservations:dry-run-v1-batch2', [
            '--candidates' => $this->candidatesPath,
        ]));
        $this->assertSame($this->expectedClassificationReport(), \Illuminate\Support\Facades\Artisan::output());
        $this->assertArrayNotHasKey('execute', app(\App\Console\Commands\DryRunV1Batch2Command::class)->getDefinition()->getOptions());

        $this->assertSame([], $this->writes);
        $this->assertNull($rows['C']->fresh()->user_id);
        $this->assertSame(1, ReservationAttribution::query()->count());

        $parsed = $this->parsedCandidates();
        $this->assertCount(9, file($this->candidatesPath));
        $this->assertCount(8, $parsed);
        $this->assertSame([
            $rows['R']->id => ['user' => $alpha->id, 'category' => 'R'],
            $rows['A1']->id => ['user' => $beta->id, 'category' => 'A1'],
            $rows['A1null']->id => ['user' => $delta->id, 'category' => 'A1'],
            $rows['A2']->id => ['user' => $alpha->id, 'category' => 'A2'],
            $rows['B']->id => ['user' => $alpha->id, 'category' => 'B'],
            $rows['Bnull']->id => ['user' => $alpha->id, 'category' => 'B'],
            $rows['C']->id => ['user' => $alpha->id, 'category' => 'C'],
            $rows['ignored_status']->id => ['user' => $alpha->id, 'category' => 'B'],
        ], $parsed);
        $contents = (string) file_get_contents($this->candidatesPath);
        $this->assertStringNotContainsString('alpha@example.com', $contents);
        $this->assertStringNotContainsString('KOZ1', $contents);
        $this->assertStringNotContainsString('Alpha Travel', $contents);
    }

    public function test_php_normalizers_keep_diacritics_case_and_gmail_dots_exact(): void
    {
        $cacak = $this->agency('Čačak Travel', 'cacak@example.com');
        $this->vehicle($cacak, 'KOZ1', Vehicle::STATUS_ACTIVE);
        $gmail = $this->agency('Gmail Dot', 'ab@gmail.com');

        $exclusive = $this->reservation($cacak, "  čačak\u{00A0}travel  ", ' Cacak@Example.com ', 'KO Ž1');
        $lowerDiacritic = $this->reservation($cacak, 'Čačak Travel', 'cacak@example.com', 'ko ž1');
        $dotted = $this->reservation($gmail, 'Gmail Dot', 'a.b@gmail.com', 'KOZ1');

        $this->listenForWrites();

        $result = app(\App\Services\Reservation\HistoricalAttributionBatch2DryRun::class)->classify();
        $this->assertSame(['R' => 1, 'A1' => 0, 'A2' => 0, 'B' => 1, 'C' => 0, 'D' => 0, 'E' => 1, 'F' => 0], $result['counts']);
        $this->assertSame(2, $result['identity_candidates']);
        $this->assertSame(1, $result['distinct_agencies']);
        $this->assertSame(3, $result['eligible']);
        $this->assertSame($result['eligible'], $result['category_sum']);

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('reservations:dry-run-v1-batch2'));
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('Identity candidates: 2'.PHP_EOL, $output);
        $this->assertStringContainsString('Distinct candidate agencies: 1'.PHP_EOL, $output);
        $this->assertStringContainsString('No reservation or audit row was written.'.PHP_EOL, $output);

        $this->assertSame([], $this->writes);
        $this->assertNull($exclusive->fresh()->user_id);
        $this->assertNull($lowerDiacritic->fresh()->user_id);
        $this->assertNull($dotted->fresh()->user_id);
        $this->assertFileDoesNotExist($this->candidatesPath);
    }

    public function test_existing_candidates_file_is_not_overwritten(): void
    {
        file_put_contents($this->candidatesPath, "keep\n");

        $this->artisan('reservations:dry-run-v1-batch2', [
            '--candidates' => $this->candidatesPath,
        ])->expectsOutputToContain('already exists and was not overwritten.')
            ->assertFailed();

        $this->assertSame("keep\n", file_get_contents($this->candidatesPath));
    }

    public function test_candidates_path_inside_the_repository_is_refused(): void
    {
        $path = base_path('v1-batch2-should-not-exist.csv');

        $this->artisan('reservations:dry-run-v1-batch2', [
            '--candidates' => $path,
        ])->expectsOutputToContain('outside the repository')
            ->assertFailed();

        $this->assertFileDoesNotExist($path);
    }

    public function test_missing_candidates_directory_is_refused(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v1-batch2-missing-'.uniqid('', true).DIRECTORY_SEPARATOR.'out.csv';

        $this->artisan('reservations:dry-run-v1-batch2', [
            '--candidates' => $path,
        ])->expectsOutputToContain('existing directory')
            ->assertFailed();

        $this->assertFileDoesNotExist($path);
    }

    public function test_missing_audit_table_fails_without_migrating(): void
    {
        Schema::drop('reservation_attributions');

        try {
            $this->artisan('reservations:dry-run-v1-batch2')
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

    private function expectedClassificationReport(): string
    {
        $lines = [
            'Scanned V1 NULL-user reservations: 12',
            'Excluded active attribution audit: 1',
            'Eligible: 11',
            'R: 1',
            'A1: 2',
            'A2: 1',
            'B: 3',
            'C: 1',
            'D: 1',
            'E: 1',
            'F: 1',
            'Category sum: 11',
            'Identity candidates: 8',
            'Distinct candidate agencies: 3',
            'Distinct agencies R: 1',
            'Distinct agencies A1: 2',
            'Distinct agencies A2: 1',
            'Distinct agencies B: 1',
            'Distinct agencies C: 1',
            'A1 other agency: 1',
            'A1 extra null owner: 1',
            'Candidates file: '.$this->candidatesPath,
            'No reservation or audit row was written.',
            'Identity candidates are not approved for attribution.',
        ];

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * @return array<int, array{user: int, category: string}>
     */
    private function parsedCandidates(): array
    {
        $handle = fopen($this->candidatesPath, 'rb');
        $this->assertNotFalse($handle);
        $header = fgetcsv($handle);
        $this->assertSame(['reservation_id', 'proposed_user_id', 'plate_category'], $header);
        $parsed = [];
        $previousId = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $id = (int) $row[0];
            $this->assertGreaterThan($previousId, $id);
            $previousId = $id;
            $parsed[$id] = ['user' => (int) $row[1], 'category' => (string) $row[2]];
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

    private function ambiguousEmailReservation(): Reservation
    {
        $this->agency('Left Case', 'Case@Example.com');
        $this->agency('Right Case', 'case@example.com');

        return $this->reservation(null, 'Left Case', 'CASE@example.com', 'CASEPLATE');
    }

    private function agency(string $name, string $email): User
    {
        return User::factory()->create([
            'name' => $name,
            'email' => $email,
        ]);
    }

    private function vehicle(?User $agency, string $plate, string $status): void
    {
        // SQLite tests do not apply the production nullable-user migration.
        // The scanner stores a null vehicle user id as owner 0. A deferred
        // foreign key lets this transaction insert that owner without a user.
        if ($agency === null) {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }

        Vehicle::query()->create([
            'user_id' => $agency?->id ?? 0,
            'license_plate' => $plate,
            'vehicle_type_id' => $this->vehicleType()->id,
            'status' => $status,
        ]);
    }

    private function reservation(?User $agency, string $name, string $email, string $plate, string $createdAt = '2026-04-01 10:00:00'): Reservation
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
