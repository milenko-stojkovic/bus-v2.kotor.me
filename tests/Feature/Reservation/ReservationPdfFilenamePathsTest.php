<?php

namespace Tests\Feature\Reservation;

use App\Jobs\SendFreeReservationConfirmationJob;
use App\Jobs\SendInvoiceEmailJob;
use App\Mail\FreeReservationRequestFulfilledMail;
use App\Models\Admin;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\User;
use App\Models\VehicleType;
use App\Services\Pdf\FreeReservationPdfGenerator;
use App\Services\Reservation\ReservationEmailSendClaimService;
use App\Support\ReservationPdfFilename;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class ReservationPdfFilenamePathsTest extends TestCase
{
    use RefreshDatabase;

    private function mockPaidPdf(): void
    {
        $this->app->instance(PaidInvoicePdfGenerator::class, new class extends PaidInvoicePdfGenerator {
            public function renderBinary(\App\Models\Reservation $reservation, bool $isFiscal): string
            {
                return '%PDF-1.4';
            }
        });
    }

    private function mockFreePdf(): void
    {
        $this->app->instance(FreeReservationPdfGenerator::class, new class extends FreeReservationPdfGenerator {
            public function renderBinary(\App\Models\Reservation $reservation): string
            {
                return '%PDF-1.4';
            }
        });
    }

    /** @return array{drop: ListOfTimeSlot, pick: ListOfTimeSlot, vt: VehicleType} */
    private function seedSlotsAndType(): array
    {
        $drop = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $pick = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $vt = VehicleType::query()->create(['price' => 25.00]);

        return compact('drop', 'pick', 'vt');
    }

    private function makePaidReservation(array $overrides = []): Reservation
    {
        $seed = $this->seedSlotsAndType();

        return Reservation::query()->create(array_merge([
            'drop_off_time_slot_id' => $seed['drop']->id,
            'pick_up_time_slot_id' => $seed['pick']->id,
            'reservation_date' => '2026-06-25',
            'user_name' => 'Paid Guest',
            'country' => 'ME',
            'license_plate' => 'PGPDF01',
            'vehicle_type_id' => $seed['vt']->id,
            'email' => 'paid@example.com',
            'status' => 'paid',
            'invoice_amount' => '25.00',
        ], $overrides));
    }

    private function makeFreeReservation(array $overrides = []): Reservation
    {
        $seed = $this->seedSlotsAndType();

        return Reservation::query()->create(array_merge([
            'drop_off_time_slot_id' => $seed['drop']->id,
            'pick_up_time_slot_id' => $seed['pick']->id,
            'reservation_date' => '2026-06-26',
            'user_name' => 'Free Guest',
            'country' => 'ME',
            'license_plate' => 'PGFREE1',
            'vehicle_type_id' => $seed['vt']->id,
            'email' => 'free@example.com',
            'status' => 'free',
            'invoice_amount' => '0.00',
        ], $overrides));
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

    public function test_paid_invoice_email_attachment_uses_standard_filename(): void
    {
        config([
            'mail.from.address' => 'bus@kotor.me',
            'mail.from.name' => 'Kotor Bus',
            'mail.default' => 'array',
        ]);
        $this->mockPaidPdf();

        $reservation = $this->makePaidReservation([
            'merchant_transaction_id' => 'mt-pdf-name-paid',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
        $expected = ReservationPdfFilename::invoice($reservation);

        $names = $this->captureMailAttachmentNames(function () use ($reservation): void {
            (new SendInvoiceEmailJob($reservation->id, false))->handle(
                app(PaidInvoicePdfGenerator::class),
                app(ReservationEmailSendClaimService::class),
            );
        });

        $this->assertSame([$expected], $names);
        $this->assertSame('invoice-'.$reservation->id.'-2026-06-25.pdf', $expected);
    }

    public function test_free_confirmation_email_attachment_uses_standard_filename(): void
    {
        config([
            'mail.from.address' => 'bus@kotor.me',
            'mail.from.name' => 'Kotor Bus',
            'mail.default' => 'array',
        ]);
        $this->mockFreePdf();

        $reservation = $this->makeFreeReservation([
            'merchant_transaction_id' => 'mt-pdf-name-free',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ]);
        $expected = ReservationPdfFilename::freeConfirmation($reservation);

        $names = $this->captureMailAttachmentNames(function () use ($reservation): void {
            (new SendFreeReservationConfirmationJob($reservation->id))->handle(
                app(FreeReservationPdfGenerator::class),
                app(ReservationEmailSendClaimService::class),
            );
        });

        $this->assertSame([$expected], $names);
        $this->assertSame('free-confirmation-'.$reservation->id.'-2026-06-26.pdf', $expected);
    }

    public function test_admin_paid_reservation_pdf_download_uses_invoice_filename(): void
    {
        $this->mockPaidPdf();
        $admin = Admin::query()->create([
            'username' => 'pdfadmin',
            'email' => 'pdf-admin@example.com',
            'password' => bcrypt('secret'),
            'admin_access' => true,
        ]);
        $reservation = $this->makePaidReservation();

        $response = $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.reservations.pdf', $reservation, false));

        $response->assertOk();
        $this->assertStringContainsString(
            'invoice-'.$reservation->id.'-2026-06-25.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_admin_free_reservation_pdf_download_uses_free_confirmation_filename(): void
    {
        $this->mockFreePdf();
        $admin = Admin::query()->create([
            'username' => 'pdffreeadmin',
            'email' => 'pdf-free-admin@example.com',
            'password' => bcrypt('secret'),
            'admin_access' => true,
        ]);
        $reservation = $this->makeFreeReservation();

        $response = $this->actingAs($admin, 'panel_admin')
            ->get(route('panel_admin.reservations.pdf', $reservation, false));

        $response->assertOk();
        $this->assertStringContainsString(
            'free-confirmation-'.$reservation->id.'-2026-06-26.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_agency_realized_paid_pdf_view_uses_invoice_filename(): void
    {
        $this->mockPaidPdf();
        $user = User::factory()->create();
        $reservation = $this->makePaidReservation(['user_id' => $user->id, 'email' => $user->email]);

        $response = $this->actingAs($user)
            ->get(route('panel.reservations.invoice.view', ['id' => $reservation->id], false));

        $response->assertOk();
        $this->assertStringContainsString(
            'invoice-'.$reservation->id.'-2026-06-25.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_agency_realized_free_pdf_view_uses_free_confirmation_filename(): void
    {
        $this->mockFreePdf();
        $user = User::factory()->create();
        $reservation = $this->makeFreeReservation(['user_id' => $user->id, 'email' => $user->email]);

        $response = $this->actingAs($user)
            ->get(route('panel.reservations.invoice.view', ['id' => $reservation->id], false));

        $response->assertOk();
        $this->assertStringContainsString(
            'free-confirmation-'.$reservation->id.'-2026-06-26.pdf',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_fzbr_fulfilled_mail_pdf_attachments_use_free_confirmation_filename(): void
    {
        $reservation = $this->makeFreeReservation();
        $filename = ReservationPdfFilename::freeConfirmation($reservation);

        $mail = new FreeReservationRequestFulfilledMail('body', 'subject', [
            ['path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'test.pdf', 'filename' => $filename],
        ]);

        $this->assertSame(
            'free-confirmation-'.$reservation->id.'-2026-06-26.pdf',
            $mail->pdfAttachments[0]['filename'],
        );
    }

    public function test_no_reservation_id_only_pdf_pattern_in_app_reservation_paths(): void
    {
        $paths = [
            base_path('app/Http/Controllers/AdminPanel/ReservationController.php'),
            base_path('app/Http/Controllers/UserReservationController.php'),
            base_path('app/Jobs/SendInvoiceEmailJob.php'),
            base_path('app/Jobs/SendFreeReservationConfirmationJob.php'),
            base_path('app/Jobs/SendAdminUpdatedReservationDocumentJob.php'),
            base_path('app/Services/AdminPanel/FreeReservation/FreeReservationRequestFulfillmentService.php'),
        ];

        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            $this->assertIsString($contents);
            $this->assertStringNotContainsString("'reservation-'.$", $contents, $path);
            $this->assertStringNotContainsString('"reservation-".$', $contents, $path);
        }
    }
}
