<?php

namespace Tests\Feature\Email;

use App\Console\Commands\AuditReservationDocumentsCommand;
use App\Console\Commands\ResendReservationDocumentCommand;
use App\Console\Commands\SendReservationEmails;
use App\Jobs\SendAdminUpdatedReservationDocumentJob;
use App\Jobs\SendFreeReservationConfirmationJob;
use App\Jobs\SendInvoiceEmailJob;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\VehicleType;
use App\Services\AgencyAdvance\AdvanceTopupConfirmationService;
use App\Services\Pdf\FreeReservationPdfGenerator;
use App\Services\Pdf\PaidInvoicePdfGenerator;
use App\Services\Reservation\ReservationDocumentResendService;
use App\Services\Reservation\ReservationEmailSendClaimService;
use App\Support\ReservationKind;
use App\Support\ReservationPdfFilename;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class ReservationDocumentEmailHardeningTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<MessageLogged> */
    private array $paymentLogEvents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->paymentLogEvents = [];
        Log::listen(function (MessageLogged $event): void {
            if (($event->context['event'] ?? $event->message) !== '') {
                $this->paymentLogEvents[] = $event;
            }
        });

        config([
            'mail.from.address' => 'bus@kotor.me',
            'mail.from.name' => 'Kotor Bus',
            'mail.default' => 'array',
        ]);

        $this->mockPaidPdf();
        $this->mockFreePdf();
    }

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
            'reservation_date' => '2026-06-26',
            'user_name' => 'Paid Guest',
            'country' => 'ME',
            'license_plate' => 'PGPDF01',
            'vehicle_type_id' => $seed['vt']->id,
            'email' => 'paid@example.com',
            'merchant_transaction_id' => 'mt-paid-'.uniqid(),
            'status' => 'paid',
            'invoice_amount' => '25.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
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
            'merchant_transaction_id' => 'mt-free-'.uniqid(),
            'status' => 'free',
            'invoice_amount' => '0.00',
            'email_sent' => Reservation::EMAIL_NOT_SENT,
        ], $overrides));
    }

    private function makeDailyTicketReservation(array $overrides = []): Reservation
    {
        return $this->makePaidReservation(array_merge([
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'drop_off_time_slot_id' => null,
            'pick_up_time_slot_id' => null,
        ], $overrides));
    }

    /** @param  array<string, mixed>  $contextSubset */
    private function assertPaymentLog(string $message, array $contextSubset = []): void
    {
        $matched = false;
        foreach ($this->paymentLogEvents as $event) {
            if ($event->message !== $message) {
                continue;
            }
            $ok = true;
            foreach ($contextSubset as $key => $value) {
                if (($event->context[$key] ?? null) !== $value) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $matched = true;
                break;
            }
        }

        $this->assertTrue($matched, 'Expected payments log message ['.$message.'] with '.json_encode($contextSubset));
    }

    public function test_paid_invoice_send_logs_started_and_sent_with_full_context(): void
    {
        $reservation = $this->makePaidReservation([
            'merchant_transaction_id' => 'mt-log-paid',
        ]);
        $expectedFilename = ReservationPdfFilename::invoice($reservation);

        (new SendInvoiceEmailJob($reservation->id, false))->handle(
            app(PaidInvoicePdfGenerator::class),
            app(ReservationEmailSendClaimService::class),
        );

        $this->assertPaymentLog('paid_invoice_email_started', [
            'reservation_id' => $reservation->id,
            'merchant_transaction_id' => 'mt-log-paid',
            'recipient_email' => 'paid@example.com',
            'attachment_filename' => $expectedFilename,
        ]);
        $this->assertPaymentLog('paid_invoice_email_sent', [
            'reservation_id' => $reservation->id,
            'merchant_transaction_id' => 'mt-log-paid',
            'recipient_email' => 'paid@example.com',
            'attachment_filename' => $expectedFilename,
        ]);
    }

    public function test_daily_fee_invoice_send_logs_started_and_sent(): void
    {
        $reservation = $this->makeDailyTicketReservation([
            'merchant_transaction_id' => 'mt-daily-log',
        ]);
        $expectedFilename = ReservationPdfFilename::invoice($reservation);

        (new SendInvoiceEmailJob($reservation->id, false))->handle(
            app(PaidInvoicePdfGenerator::class),
            app(ReservationEmailSendClaimService::class),
        );

        $this->assertPaymentLog('paid_invoice_email_started', [
            'reservation_id' => $reservation->id,
            'reservation_kind' => ReservationKind::DAILY_TICKET,
            'attachment_filename' => $expectedFilename,
        ]);
        $this->assertPaymentLog('paid_invoice_email_sent', [
            'reservation_id' => $reservation->id,
            'attachment_filename' => $expectedFilename,
        ]);
    }

    public function test_free_confirmation_send_logs_started_and_sent(): void
    {
        $reservation = $this->makeFreeReservation([
            'merchant_transaction_id' => 'mt-free-log',
        ]);
        $expectedFilename = ReservationPdfFilename::freeConfirmation($reservation);

        (new SendFreeReservationConfirmationJob($reservation->id))->handle(
            app(FreeReservationPdfGenerator::class),
            app(ReservationEmailSendClaimService::class),
        );

        $this->assertPaymentLog('free_reservation_email_started', [
            'reservation_id' => $reservation->id,
            'recipient_email' => 'free@example.com',
            'attachment_filename' => $expectedFilename,
        ]);
        $this->assertPaymentLog('free_reservation_email_sent', [
            'reservation_id' => $reservation->id,
            'attachment_filename' => $expectedFilename,
        ]);
    }

    public function test_admin_updated_document_send_logs_started_and_sent(): void
    {
        $reservation = $this->makePaidReservation([
            'merchant_transaction_id' => 'mt-admin-upd',
            'fiscal_jir' => 'JIR123',
        ]);
        $expectedFilename = ReservationPdfFilename::invoice($reservation);

        (new SendAdminUpdatedReservationDocumentJob($reservation->id, 99, ['license_plate']))->handle(
            app(PaidInvoicePdfGenerator::class),
            app(FreeReservationPdfGenerator::class),
            app(ReservationEmailSendClaimService::class),
        );

        $this->assertPaymentLog('admin_panel_reservation_update_email_started', [
            'reservation_id' => $reservation->id,
            'attachment_filename' => $expectedFilename,
        ]);
        $this->assertPaymentLog('admin_panel_reservation_update_email_sent', [
            'reservation_id' => $reservation->id,
            'attachment_filename' => $expectedFilename,
        ]);
    }

    public function test_mail_failure_does_not_set_email_sent_or_invoice_sent_at(): void
    {
        Mail::shouldReceive('raw')->andThrow(new RuntimeException('SMTP rejected'));

        $reservation = $this->makePaidReservation();

        try {
            (new SendInvoiceEmailJob($reservation->id, false))->handle(
                app(PaidInvoicePdfGenerator::class),
                app(ReservationEmailSendClaimService::class),
            );
            $this->fail('Expected exception');
        } catch (RuntimeException) {
            // expected
        }

        $fresh = $reservation->fresh();
        $this->assertSame(Reservation::EMAIL_NOT_SENT, (int) $fresh->email_sent);
        $this->assertNull($fresh->invoice_sent_at);
        $this->assertPaymentLog('paid_invoice_email_failed', [
            'reservation_id' => $reservation->id,
            'exception' => RuntimeException::class,
        ]);
    }

    public function test_mail_failure_rethrows_for_queue_retry(): void
    {
        Mail::shouldReceive('raw')->andThrow(new RuntimeException('SMTP rejected'));

        $reservation = $this->makePaidReservation();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SMTP rejected');

        (new SendInvoiceEmailJob($reservation->id, false))->handle(
            app(PaidInvoicePdfGenerator::class),
            app(ReservationEmailSendClaimService::class),
        );
    }

    public function test_pdf_filename_helper_fallback_never_throws_for_valid_reservation(): void
    {
        $this->assertSame('2026-03-15', ReservationPdfFilename::sanitizeDateSegment('2026-03-15/../evil'));
        $this->assertSame(now()->format('Y-m-d'), ReservationPdfFilename::sanitizeDateSegment('invalid'));

        $reservation = $this->makePaidReservation([
            'reservation_date' => '2026-03-15',
        ]);

        $filename = ReservationPdfFilename::invoice($reservation);
        $this->assertSame('invoice-'.$reservation->id.'-2026-03-15.pdf', $filename);
    }

    public function test_stale_email_sending_lock_is_reclaimed(): void
    {
        $reservation = $this->makePaidReservation([
            'email_sent' => Reservation::EMAIL_SENDING,
        ]);
        DB::table('reservations')->where('id', $reservation->id)->update([
            'updated_at' => now()->subMinutes(20),
        ]);

        (new SendInvoiceEmailJob($reservation->id, false))->handle(
            app(PaidInvoicePdfGenerator::class),
            app(ReservationEmailSendClaimService::class),
        );

        $fresh = $reservation->fresh();
        $this->assertSame(Reservation::EMAIL_SENT, (int) $fresh->email_sent);
        $this->assertNotNull($fresh->invoice_sent_at);
    }

    public function test_resend_service_queues_job_and_resets_flags(): void
    {
        Queue::fake();

        $reservation = $this->makePaidReservation([
            'email_sent' => Reservation::EMAIL_SENT,
            'invoice_sent_at' => now(),
        ]);

        $result = app(ReservationDocumentResendService::class)->queue($reservation->id);

        $this->assertSame('queued', $result);
        $fresh = $reservation->fresh();
        $this->assertSame(Reservation::EMAIL_NOT_SENT, (int) $fresh->email_sent);
        $this->assertNull($fresh->invoice_sent_at);
        Queue::assertPushed(SendInvoiceEmailJob::class);
    }

    public function test_resend_artisan_command_queues_document_email(): void
    {
        Queue::fake();

        $reservation = $this->makeFreeReservation();

        $exit = Artisan::call('mail:resend-reservation-document', ['--id' => $reservation->id]);

        $this->assertSame(0, $exit);
        Queue::assertPushed(SendFreeReservationConfirmationJob::class);
    }

    public function test_audit_command_identifies_missing_email_rows(): void
    {
        $missing = $this->makePaidReservation([
            'email_sent' => Reservation::EMAIL_NOT_SENT,
            'invoice_sent_at' => null,
        ]);
        $sent = $this->makePaidReservation([
            'email_sent' => Reservation::EMAIL_SENT,
            'invoice_sent_at' => now(),
            'email' => 'sent@example.com',
            'merchant_transaction_id' => 'mt-sent',
        ]);

        $exit = Artisan::call('mail:audit-reservation-documents', [
            '--date' => '2026-06-26',
            '--missing-only' => true,
        ]);

        $output = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('| '.$missing->id.' ', $output);
        $this->assertStringContainsString('YES', $output);
        $this->assertStringNotContainsString('| '.$sent->id.' ', $output);
    }

    public function test_send_reservation_emails_command_dispatches_jobs_instead_of_faking_sent(): void
    {
        Queue::fake();

        $this->makePaidReservation(['email' => 'queue@example.com']);
        $this->makeFreeReservation(['email' => 'free-queue@example.com']);

        Artisan::call('reservations:send-emails');

        Queue::assertPushed(SendInvoiceEmailJob::class);
        Queue::assertPushed(SendFreeReservationConfirmationJob::class);
    }

    public function test_successful_invoice_send_sets_flags_only_after_mail(): void
    {
        $reservation = $this->makePaidReservation();

        (new SendInvoiceEmailJob($reservation->id, false))->handle(
            app(PaidInvoicePdfGenerator::class),
            app(ReservationEmailSendClaimService::class),
        );

        $fresh = $reservation->fresh();
        $this->assertSame(Reservation::EMAIL_SENT, (int) $fresh->email_sent);
        $this->assertNotNull($fresh->invoice_sent_at);
    }

    public function test_resend_uses_standardized_filename_on_send(): void
    {
        $reservation = $this->makePaidReservation([
            'merchant_transaction_id' => 'mt-resend-filename',
        ]);
        $expected = ReservationPdfFilename::invoice($reservation);

        (new SendInvoiceEmailJob($reservation->id, false))->handle(
            app(PaidInvoicePdfGenerator::class),
            app(ReservationEmailSendClaimService::class),
        );

        $this->assertPaymentLog('paid_invoice_email_sent', [
            'attachment_filename' => $expected,
        ]);
        $this->assertSame('invoice-'.$reservation->id.'-2026-06-26.pdf', $expected);
    }
}
