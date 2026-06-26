<?php

namespace Tests\Feature\Panel;

use App\Jobs\SendAdminUpdatedReservationDocumentJob;
use App\Jobs\SendInvoiceEmailJob;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use App\Services\Pdf\FreeReservationPdfGenerator;
use App\Services\Reservation\PanelReservationListService;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Database\Seeders\UiTranslationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class FreeReservationPlateChangeEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UiTranslationsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_free_plate_change_updates_plate_successfully(): void
    {
        [$user, $reservation, $replacement] = $this->upcomingFreeReservationWithSpareVehicle();

        $this->actingAs($user)
            ->from(route('panel.upcoming', [], false))
            ->patch(route('panel.reservations.vehicle', $reservation->id, false), [
                'vehicle_id' => $replacement->id,
            ])
            ->assertRedirect(route('panel.upcoming', [], false));

        $reservation->refresh();
        $this->assertSame($replacement->license_plate, $reservation->license_plate);
        $this->assertSame((int) $replacement->id, (int) $reservation->vehicle_id);
    }

    public function test_free_plate_change_dispatches_updated_document_email_job(): void
    {
        Queue::fake();
        [$user, $reservation, $replacement] = $this->upcomingFreeReservationWithSpareVehicle();

        $this->actingAs($user)
            ->from(route('panel.upcoming', [], false))
            ->patch(route('panel.reservations.vehicle', $reservation->id, false), [
                'vehicle_id' => $replacement->id,
            ])
            ->assertRedirect(route('panel.upcoming', [], false));

        Queue::assertPushed(SendAdminUpdatedReservationDocumentJob::class, function (SendAdminUpdatedReservationDocumentJob $job) use ($reservation): bool {
            return $job->reservationId === $reservation->id
                && $job->adminId === null
                && $job->changedFields === ['vehicle_id', 'license_plate'];
        });
        Queue::assertNotPushed(SendInvoiceEmailJob::class);
    }

    public function test_paid_plate_change_still_dispatches_invoice_email_job_only(): void
    {
        Queue::fake();
        [$user, $reservation, $replacement] = $this->upcomingPaidReservationWithSpareVehicle();

        $this->actingAs($user)
            ->from(route('panel.upcoming', [], false))
            ->patch(route('panel.reservations.vehicle', $reservation->id, false), [
                'vehicle_id' => $replacement->id,
            ])
            ->assertRedirect(route('panel.upcoming', [], false));

        Queue::assertPushed(SendInvoiceEmailJob::class, function (SendInvoiceEmailJob $job) use ($reservation): bool {
            return $job->reservationId === $reservation->id;
        });
        Queue::assertNotPushed(SendAdminUpdatedReservationDocumentJob::class);
    }

    public function test_email_failure_does_not_roll_back_free_plate_change(): void
    {
        Queue::fake();
        config([
            'mail.from.address' => 'bus@kotor.me',
            'mail.from.name' => 'Kotor Bus',
            'mail.default' => 'array',
        ]);
        $this->mockFreePdf();
        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('SMTP down'));

        [$user, $reservation, $replacement] = $this->upcomingFreeReservationWithSpareVehicle();
        $reservation->update([
            'invoice_sent_at' => now(),
            'email_sent' => Reservation::EMAIL_SENT,
        ]);

        $this->actingAs($user)
            ->from(route('panel.upcoming', [], false))
            ->patch(route('panel.reservations.vehicle', $reservation->id, false), [
                'vehicle_id' => $replacement->id,
            ])
            ->assertRedirect(route('panel.upcoming', [], false));

        $reservation->refresh();
        $this->assertSame($replacement->license_plate, $reservation->license_plate);
        $this->assertSame((int) $replacement->id, (int) $reservation->vehicle_id);

        Queue::assertPushed(SendAdminUpdatedReservationDocumentJob::class);

        try {
            (new SendAdminUpdatedReservationDocumentJob($reservation->id))->handle(
                app(\App\Services\Pdf\PaidInvoicePdfGenerator::class),
                app(FreeReservationPdfGenerator::class),
            );
            $this->fail('Expected mail failure exception.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SMTP down', $e->getMessage());
        }

        $reservation->refresh();
        $this->assertSame($replacement->license_plate, $reservation->license_plate);
        $this->assertSame(Reservation::EMAIL_NOT_SENT, (int) $reservation->email_sent);
    }

    public function test_free_plate_change_pdf_button_still_on_upcoming_page(): void
    {
        [$user, $reservation] = $this->upcomingFreeReservationWithSpareVehicle();

        $this->actingAs($user)
            ->get(route('panel.upcoming', [], false))
            ->assertOk()
            ->assertSee(route('panel.reservations.invoice.view', ['id' => $reservation->id], false), false);
    }

    /**
     * @return array{0: User, 1: Reservation, 2: Vehicle}
     */
    private function upcomingFreeReservationWithSpareVehicle(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', PanelReservationListService::OPERATIONS_TIMEZONE));

        $user = User::factory()->create();
        $vt = $this->busType();
        $slotA = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slotB = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);

        $current = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'FR100',
            'vehicle_type_id' => $vt->id,
        ]);
        $replacement = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'FR200',
            'vehicle_type_id' => $vt->id,
        ]);

        $reservation = Reservation::query()->create([
            'user_id' => $user->id,
            'vehicle_id' => $current->id,
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'reservation_date' => '2026-07-15',
            'user_name' => 'Agency',
            'country' => 'ME',
            'license_plate' => $current->license_plate,
            'vehicle_type_id' => $vt->id,
            'email' => $user->email,
            'status' => 'free',
            'invoice_amount' => 0,
            'email_sent' => Reservation::EMAIL_SENT,
            'invoice_sent_at' => now(),
        ]);

        return [$user, $reservation, $replacement];
    }

    /**
     * @return array{0: User, 1: Reservation, 2: Vehicle}
     */
    private function upcomingPaidReservationWithSpareVehicle(): array
    {
        $user = User::factory()->create();
        $vt = $this->busType();
        $slotA = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slotB = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $date = Carbon::now()->addDays(3)->toDateString();

        $current = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'TS100',
            'vehicle_type_id' => $vt->id,
        ]);
        $replacement = Vehicle::query()->create([
            'user_id' => $user->id,
            'license_plate' => 'TS200',
            'vehicle_type_id' => $vt->id,
        ]);

        $reservation = Reservation::query()->create([
            'user_id' => $user->id,
            'vehicle_id' => $current->id,
            'reservation_kind' => ReservationKind::TIME_SLOTS,
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'reservation_date' => $date,
            'user_name' => 'Agency',
            'country' => 'ME',
            'license_plate' => $current->license_plate,
            'vehicle_type_id' => $vt->id,
            'email' => $user->email,
            'status' => 'paid',
            'invoice_amount' => 10,
        ]);

        return [$user, $reservation, $replacement];
    }

    private function mockFreePdf(): void
    {
        $this->app->instance(FreeReservationPdfGenerator::class, new class extends FreeReservationPdfGenerator {
            public function renderBinary(Reservation $reservation): string
            {
                return '%PDF-1.4';
            }
        });
    }

    private function busType(): VehicleType
    {
        $vt = VehicleType::query()->create(['price' => 10]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $vt->id,
            'locale' => 'en',
            'name' => 'Bus',
            'description' => null,
        ]);
        VehicleTypeTranslation::query()->create([
            'vehicle_type_id' => $vt->id,
            'locale' => 'cg',
            'name' => 'Autobus',
            'description' => null,
        ]);

        return $vt;
    }
}
