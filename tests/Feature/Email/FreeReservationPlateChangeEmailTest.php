<?php

namespace Tests\Feature\Email;

use App\Jobs\SendAdminUpdatedReservationDocumentJob;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Pdf\FreeReservationPdfGenerator;
use App\Support\ReservationPdfFilename;
use Database\Seeders\UiTranslationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use ReflectionMethod;
use Tests\TestCase;

final class FreeReservationPlateChangeEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UiTranslationsSeeder::class);
    }

    public function test_updated_free_plate_change_email_attachment_uses_standard_filename(): void
    {
        config([
            'mail.from.address' => 'bus@kotor.me',
            'mail.from.name' => 'Kotor Bus',
            'mail.default' => 'array',
        ]);
        $this->mockFreePdf();

        $reservation = $this->makeAgencyFreeReservation();
        $expected = ReservationPdfFilename::freeConfirmation($reservation);

        $names = $this->captureMailAttachmentNames(function () use ($reservation): void {
            (new SendAdminUpdatedReservationDocumentJob($reservation->id))->handle(
                app(\App\Services\Pdf\PaidInvoicePdfGenerator::class),
                app(FreeReservationPdfGenerator::class),
            );
        });

        $this->assertSame([$expected], $names);
        $this->assertSame(
            'free-confirmation-'.$reservation->id.'-2026-07-15.pdf',
            $expected,
        );
    }

    public function test_updated_free_plate_change_email_body_includes_reservation_reference(): void
    {
        $reservation = $this->makeAgencyFreeReservation([
            'merchant_transaction_id' => null,
        ]);

        $job = new SendAdminUpdatedReservationDocumentJob($reservation->id);
        $method = new ReflectionMethod($job, 'buildBody');
        $method->setAccessible(true);
        $body = $method->invoke($job, $reservation->fresh(), 'cg');

        $this->assertStringContainsString('Broj rezervacije: '.$reservation->id, $body);
        $this->assertStringContainsString('izmijenjeni', $body);
    }

    public function test_updated_free_plate_change_email_body_uses_transaction_reference_when_mtid_exists(): void
    {
        $reservation = $this->makeAgencyFreeReservation([
            'merchant_transaction_id' => 'mt-free-plate-change',
        ]);

        $job = new SendAdminUpdatedReservationDocumentJob($reservation->id);
        $method = new ReflectionMethod($job, 'buildBody');
        $method->setAccessible(true);
        $body = $method->invoke($job, $reservation->fresh(), 'en');

        $this->assertStringContainsString('Transaction reference: mt-free-plate-change', $body);
    }

    private function makeAgencyFreeReservation(array $overrides = []): Reservation
    {
        $user = User::factory()->create();
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $vt = VehicleType::query()->create(['price' => 0]);

        return Reservation::query()->create(array_merge([
            'user_id' => $user->id,
            'drop_off_time_slot_id' => $drop->id,
            'pick_up_time_slot_id' => $pick->id,
            'reservation_date' => '2026-07-15',
            'user_name' => 'Agency Free',
            'country' => 'ME',
            'license_plate' => 'FR300',
            'vehicle_type_id' => $vt->id,
            'email' => $user->email,
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ], $overrides));
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

    /** @return list<string> */
    private function captureMailAttachmentNames(callable $callback): array
    {
        $names = [];
        Event::listen(MessageSending::class, function (MessageSending $event) use (&$names): void {
            foreach ($event->message->getAttachments() as $attachment) {
                $names[] = $attachment->getFilename();
            }
        });

        $callback();

        return $names;
    }
}
