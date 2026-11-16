<?php

namespace Tests\Feature\Reservation;

use App\Console\Commands\DiagnoseV1Batch3Command;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Reservation\HistoricalAttributionBatch3Diagnostics;
use App\Support\ReservationKind;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class HistoricalAttributionBatch3DiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private string $reportPath;

    /** @var object{listen: bool, writes: list<string>} */
    private object $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reportPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'v1-batch3-diag-'.uniqid('', true).'.csv';
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

    public function test_diagnostics_review_class_8_rows_without_changing_membership_or_writing(): void
    {
        $alpha = $this->agency('Alpha Travel', 'alpha@example.com', 'ME');
        $beta = $this->agency('Beta Travel', 'beta@example.com', 'DE');
        $short = $this->agency('AB', 'short@example.com', 'ME');
        $generic = $this->agency('Rent A Car', 'rentacar@example.com', 'ME');

        $mismatch = $this->reservation('Alpha Travel', 'shared@example.com', 'DE', '2026-01-10');
        $betaShared = $this->reservation('Beta Travel', '  Shared@Example.com ', 'DE', '2026-03-01');
        $sameCountry = $this->reservation('Alpha Travel', 'shared@example.com', 'me', '2026-02-02');
        $alphaOnly = $this->reservation('Alpha Travel', 'only-alpha@example.com', 'ME', '2026-04-01');
        $shortRow = $this->reservation('AB', 'visitor@example.com', 'FR', '2026-06-01');
        $genericRow = $this->reservation('Rent A Car', 'guest-desk@example.com', 'ME', '2026-05-15');
        $blank = $this->reservation('Alpha Travel', '', 'XX', '2026-04-02');
        $identity = $this->reservation('Alpha Travel', 'alpha@example.com', 'FR', '2026-04-03');
        $attributed = $this->reservation('Alpha Travel', 'stranger@example.com', 'FR', '2026-04-04');
        DB::table('reservations')->where('id', $attributed->id)->update(['user_id' => $alpha->id]);

        $before = $this->storedRows();
        $this->gate->listen = true;
        $result = app(HistoricalAttributionBatch3Diagnostics::class)->collect([$alpha->id, $beta->id]);
        $this->assertSame([], $this->gate->writes);
        $this->assertSame($before, $this->storedRows());

        $this->assertTrue($result['reconciliation_passed']);
        $this->assertTrue($result['recount_agrees']);
        $this->assertSame(6, $result['class8']);
        $this->assertSame(6, $result['loaded']);
        $this->assertSame([
            [
                'label' => 'Shared email 1',
                'email' => 'shared@example.com',
                'reservations' => 3,
                'agencies' => [
                    ['agency_id' => $alpha->id, 'reservations' => 2],
                    ['agency_id' => $beta->id, 'reservations' => 1],
                ],
            ],
        ], $result['shared_emails']);
        $this->assertSame([
            [
                'reservation_id' => $mismatch->id,
                'agency_id' => $alpha->id,
                'reservation_country' => 'DE',
                'agency_country' => 'ME',
            ],
            [
                'reservation_id' => $shortRow->id,
                'agency_id' => $short->id,
                'reservation_country' => 'FR',
                'agency_country' => 'ME',
            ],
        ], $result['country_mismatches']);
        $this->assertSame([
            [
                'agency_id' => $alpha->id,
                'reservations' => 3,
                'distinct_emails' => 2,
                'largest_email_group' => 2,
                'date_min' => '2026-01-10',
                'date_max' => '2026-04-01',
                'normalized_name' => 'alpha travel',
                'emails' => [
                    ['email' => 'shared@example.com', 'reservations' => 2, 'date_min' => '2026-01-10', 'date_max' => '2026-02-02'],
                    ['email' => 'only-alpha@example.com', 'reservations' => 1, 'date_min' => '2026-04-01', 'date_max' => '2026-04-01'],
                ],
            ],
            [
                'agency_id' => $beta->id,
                'reservations' => 1,
                'distinct_emails' => 1,
                'largest_email_group' => 1,
                'date_min' => '2026-03-01',
                'date_max' => '2026-03-01',
                'normalized_name' => 'beta travel',
                'emails' => [
                    ['email' => 'shared@example.com', 'reservations' => 1, 'date_min' => '2026-03-01', 'date_max' => '2026-03-01'],
                ],
            ],
        ], $result['agencies']);
        $this->assertSame([
            ['agency_id' => $short->id, 'reservations' => 1, 'normalized_name' => 'ab', 'observation' => 'short_name'],
            ['agency_id' => $short->id, 'reservations' => 1, 'normalized_name' => 'ab', 'observation' => 'single_token'],
        ], $result['name_observations']);
        $this->assertNotContains($generic->id, array_column($result['name_observations'], 'agency_id'));
        $this->assertNotNull($genericRow->id);

        $mismatchIds = array_column($result['country_mismatches'], 'reservation_id');
        $this->assertNotContains($blank->id, $mismatchIds);
        $this->assertNotContains($identity->id, $mismatchIds);
        $this->assertNotContains($attributed->id, $mismatchIds);
        $this->assertNotContains($sameCountry->id, $mismatchIds);
        $this->assertNotContains($betaShared->id, $mismatchIds);
        $this->assertNotContains($alphaOnly->id, $mismatchIds);

        $this->assertArrayNotHasKey('execute', app(DiagnoseV1Batch3Command::class)->getDefinition()->getOptions());
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('reservations:diagnose-v1-batch3', [
            '--report' => $this->reportPath,
            '--agency' => [$alpha->id, $beta->id],
        ]));
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('Current class 8: 6'.PHP_EOL, $output);
        $this->assertStringContainsString('Shared email 1: agency '.$alpha->id.' reservations 2, agency '.$beta->id.' reservations 1, reservations 3'.PHP_EOL, $output);
        $this->assertStringContainsString('Country mismatch reservation '.$mismatch->id.' agency '.$alpha->id.': reservation DE, agency ME'.PHP_EOL, $output);
        $this->assertStringContainsString('Agency '.$alpha->id.': reservations 3, distinct emails 2, largest email group 2, reservation_date 2026-01-10 to 2026-04-01'.PHP_EOL, $output);
        $this->assertStringContainsString('Agency '.$beta->id.': reservations 1, distinct emails 1, largest email group 1, reservation_date 2026-03-01 to 2026-03-01'.PHP_EOL, $output);
        $this->assertStringContainsString('Name observation agency '.$short->id.': short_name, reservations 1'.PHP_EOL, $output);
        $this->assertStringContainsString('A name with no flag is not certified as specific.', $output);
        $this->assertStringContainsString('not the same set.', $output);
        $this->assertStringContainsString('A blank email was treated as no match.', $output);
        $this->assertStringContainsString('Diagnostic recount agrees with the classifier: yes'.PHP_EOL, $output);
        $this->assertStringNotContainsString('shared@example.com', $output);
        $this->assertStringNotContainsString('Shared@Example.com', $output);
        $this->assertStringNotContainsString('only-alpha@example.com', $output);
        $this->assertStringNotContainsString('visitor@example.com', $output);
        $this->assertStringNotContainsString('Alpha Travel', $output);
        $this->assertStringNotContainsString('alpha travel', $output);
        $this->assertStringNotContainsString('Rent A Car', $output);
        $this->assertStringNotContainsString('rent a car', $output);
        $this->assertStringNotContainsString('guest-desk@example.com', $output);
        $this->assertSame([], $this->gate->writes);

        $parsed = $this->parsedReport();
        $this->assertSame('shared@example.com', $parsed['shared_email'][0]['email']);
        $this->assertSame((string) $alpha->id, $parsed['shared_email'][0]['agency_id']);
        $this->assertSame('2', $parsed['shared_email'][0]['reservations']);
        $this->assertSame((string) $mismatch->id, $parsed['country_mismatch'][0]['reservation_id']);
        $this->assertSame('DE', $parsed['country_mismatch'][0]['reservation_country']);
        $this->assertSame('ME', $parsed['country_mismatch'][0]['agency_country']);
        $this->assertSame('shared@example.com', $parsed['agency_email'][0]['email']);
        $this->assertSame('2026-01-10', $parsed['agency_email'][0]['reservation_date_min']);
        $this->assertSame('2026-02-02', $parsed['agency_email'][0]['reservation_date_max']);
        $this->assertSame('ab', $parsed['name_observation'][0]['normalized_name']);
        $this->assertSame('short_name', $parsed['name_observation'][0]['observation']);
        $this->assertStringNotContainsString('visitor@example.com', (string) file_get_contents($this->reportPath));
    }

    public function test_report_is_refused_unless_reconciliation_agrees_and_the_write_finishes(): void
    {
        $inside = base_path('v1-batch3-diag-should-not-exist.csv');
        $this->artisan('reservations:diagnose-v1-batch3', ['--report' => $inside])
            ->expectsOutputToContain('outside the repository')
            ->assertFailed();
        $this->assertFileDoesNotExist($inside);

        file_put_contents($this->reportPath, "keep\n");
        $this->artisan('reservations:diagnose-v1-batch3', ['--report' => $this->reportPath])
            ->expectsOutputToContain('already exists and was not overwritten.')
            ->assertFailed();
        $this->assertSame("keep\n", file_get_contents($this->reportPath));
        unlink($this->reportPath);

        $this->app->make(Kernel::class)->registerCommand(new class extends DiagnoseV1Batch3Command
        {
            protected function diagnostics(HistoricalAttributionBatch3Diagnostics $diagnostics, array $agencyIds): array
            {
                $result = parent::diagnostics($diagnostics, $agencyIds);
                $result['reconciliation_passed'] = false;

                return $result;
            }
        });
        $this->artisan('reservations:diagnose-v1-batch3', ['--report' => $this->reportPath])
            ->expectsOutputToContain('Reconciliation: failed')
            ->expectsOutputToContain('No report file was written.')
            ->assertFailed();
        $this->assertFileDoesNotExist($this->reportPath);

        $this->app->make(Kernel::class)->registerCommand(new class extends DiagnoseV1Batch3Command
        {
            protected function writeReportRows($handle, array $result): void
            {
                fputcsv($handle, ['partial']);

                throw new \RuntimeException('The report file could not be written.');
            }
        });
        $this->artisan('reservations:diagnose-v1-batch3', ['--report' => $this->reportPath])
            ->expectsOutputToContain('The report file could not be written.')
            ->assertFailed();
        $this->assertFileDoesNotExist($this->reportPath);
    }

    public function test_missing_audit_table_fails_without_migrating(): void
    {
        Schema::drop('reservation_attributions');

        try {
            $this->artisan('reservations:diagnose-v1-batch3')
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

    private function reservation(string $name, string $email, string $country, string $reservationDate): Reservation
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
            'reservation_date' => $reservationDate,
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
            'license_plate' => 'KO'.$reservation->id,
            'reservation_date' => $reservationDate,
            'created_at' => '2026-04-01 10:00:00',
            'updated_at' => '2026-04-01 10:00:00',
        ]);

        return $reservation->fresh();
    }

    /**
     * @return array<string, list<array<string, string>>>
     */
    private function parsedReport(): array
    {
        $handle = fopen($this->reportPath, 'rb');
        $this->assertNotFalse($handle);
        $header = fgetcsv($handle);
        $this->assertSame([
            'record_type',
            'label',
            'email',
            'agency_id',
            'reservation_id',
            'reservations',
            'reservation_country',
            'agency_country',
            'reservation_date_min',
            'reservation_date_max',
            'normalized_name',
            'observation',
        ], $header);
        $parsed = [];
        while (($row = fgetcsv($handle)) !== false) {
            $record = array_combine($header, $row);
            $parsed[$record['record_type']][] = $record;
        }
        fclose($handle);

        return $parsed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storedRows(): array
    {
        return DB::table('reservations')
            ->orderBy('id')
            ->get(['id', 'user_id', 'email', 'user_name', 'country', 'reservation_date'])
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    private function vehicleType(): VehicleType
    {
        return VehicleType::query()->first() ?? VehicleType::query()->create(['price' => 15]);
    }
}
