<?php

namespace Tests\Feature\AdminPanel;

use App\Jobs\SendFreeReservationRequestRejectedMailJob;
use App\Mail\FreeReservationRequestRejectedMail;
use App\Models\Admin;
use App\Models\AdminAlert;
use App\Models\FreeReservationRequest;
use App\Models\FreeReservationRequestAttachment;
use App\Models\FreeReservationRequestSegment;
use App\Models\ListOfTimeSlot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class FreeReservationRequestRejectionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_rejection_without_justification_is_rejected_by_server_validation(): void
    {
        Queue::fake();
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $req = $this->seedRequest();

        $this->from(route('panel_admin.free-reservations', [], false))
            ->delete(route('panel_admin.free-reservation-requests.reject', ['freeReservationRequest' => $req->id], false), [
                'reason' => '   ',
                'free_reservation_request_id' => $req->id,
            ])
            ->assertRedirect(route('panel_admin.free-reservations', [], false))
            ->assertSessionHasErrors('reason');

        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_SUBMITTED, $req->status);
        $this->assertNull($req->rejection_reason);
        Queue::assertNotPushed(SendFreeReservationRequestRejectedMailJob::class);

        $html = $this->get(route('panel_admin.free-reservations', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('Razlog odbijanja je obavezan.', $html);
        $this->assertMatchesRegularExpression('/<details[^>]*\sopen\b/', $html);
        $this->assertStringContainsString('name="reason"', $html);
        $this->assertStringNotContainsString("confirm('Da li si siguran da želiš da odbaciš ovaj zahtjev?')", $html);
    }

    public function test_rejection_reason_shorter_than_three_characters_is_rejected_and_text_is_kept(): void
    {
        Queue::fake();
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $req = $this->seedRequest();

        $this->from(route('panel_admin.free-reservations', [], false))
            ->delete(route('panel_admin.free-reservation-requests.reject', ['freeReservationRequest' => $req->id], false), [
                'reason' => '  ab  ',
                'free_reservation_request_id' => $req->id,
            ])
            ->assertRedirect(route('panel_admin.free-reservations', [], false))
            ->assertSessionHasErrors('reason');

        $html = $this->get(route('panel_admin.free-reservations', [], false))->assertOk()->getContent();
        $this->assertStringContainsString('>ab</textarea>', $html);
        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_SUBMITTED, $req->status);
        Queue::assertNotPushed(SendFreeReservationRequestRejectedMailJob::class);
    }

    public function test_valid_rejection_stores_justification_admin_and_timestamp_and_queues_email_after_commit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 16:05:00', 'Europe/Podgorica'));
        Queue::fake();

        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $req = $this->seedRequest(status: FreeReservationRequest::STATUS_UPDATED);
        $reason = "Dokumentacija nije dovoljna.\nDostavite ovlašćenje škole.";

        $this->delete(route('panel_admin.free-reservation-requests.reject', ['freeReservationRequest' => $req->id], false), [
            'reason' => $reason,
        ])->assertRedirect()->assertSessionHas('status', 'Zahtjev je odbačen.');

        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_REJECTED, $req->status);
        $this->assertSame($reason, $req->rejection_reason);
        $this->assertSame($admin->id, (int) $req->rejected_by_admin_id);
        $this->assertNotNull($req->rejected_at);
        $this->assertTrue($req->rejected_at->equalTo(now()));
        $this->assertNull($req->rejection_notification_sent_at);
        $this->assertSame('Škola Primjer', $req->institution_name);

        Queue::assertPushed(SendFreeReservationRequestRejectedMailJob::class, function (SendFreeReservationRequestRejectedMailJob $job) use ($req): bool {
            return $job->freeReservationRequestId === (int) $req->id;
        });
    }

    public function test_rejection_keeps_attachments_and_resolves_admin_alert(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $req = $this->seedRequest();
        Storage::disk('local')->put('free-reservation-requests/'.$req->id.'/doc.pdf', 'PDF');
        FreeReservationRequestAttachment::query()->create([
            'request_id' => $req->id,
            'original_name' => 'doc.pdf',
            'stored_path' => 'free-reservation-requests/'.$req->id.'/doc.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 3,
        ]);
        $alert = AdminAlert::query()->create([
            'type' => 'free_reservation_request',
            'status' => AdminAlert::STATUS_UNREAD,
            'title' => 't',
            'message' => 'm',
            'payload_json' => ['free_reservation_request_id' => $req->id],
        ]);

        $this->delete(route('panel_admin.free-reservation-requests.reject', ['freeReservationRequest' => $req->id], false), [
            'reason' => 'Prilozi nisu dovoljni.',
        ])->assertRedirect();

        $this->assertSame(1, FreeReservationRequestAttachment::query()->where('request_id', $req->id)->count());
        Storage::disk('local')->assertExists('free-reservation-requests/'.$req->id.'/doc.pdf');
        $alert->refresh();
        $this->assertSame(AdminAlert::STATUS_DONE, $alert->status);
        $this->assertNotNull($alert->resolved_at);
        $this->assertNotNull($alert->removed_at);
        $this->assertSame(1, FreeReservationRequestSegment::query()->where('request_id', $req->id)->count());
    }

    public function test_agency_receives_rejection_email_containing_the_justification(): void
    {
        Mail::fake();
        $levelDuringSend = null;
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $req = $this->seedRequest(locale: 'cg');
        $reason = "Prva linija obrazloženja.\nDruga linija.";

        $this->delete(route('panel_admin.free-reservation-requests.reject', ['freeReservationRequest' => $req->id], false), [
            'reason' => $reason,
        ])->assertRedirect();

        $req->refresh();
        $this->assertNotNull($req->rejection_notification_sent_at);

        Mail::assertSent(FreeReservationRequestRejectedMail::class, function (FreeReservationRequestRejectedMail $mail) use ($req, $reason, &$levelDuringSend): bool {
            $levelDuringSend = DB::transactionLevel();
            $html = $mail->render();

            return $mail->hasTo('school@example.com')
                && $mail->envelope()->subject === 'Zahtjev za besplatnu rezervaciju je odbijen'
                && str_contains($html, 'Vaš zahtjev za besplatnu rezervaciju je odbijen.')
                && str_contains($html, 'Zahtjev: #'.$req->id)
                && str_contains($html, $req->reservation_date->format('d.m.Y.'))
                && str_contains($html, $reason)
                && str_contains($html, 'Razlog odbijanja')
                && str_contains($html, 'bus.kotor.me')
                && str_contains($html, 'Besplatne rezervacije')
                && ! str_contains($html, 'FZBR');
        });
        $this->assertSame(1, $levelDuringSend);
    }

    public function test_english_request_rejection_email_is_in_english(): void
    {
        Mail::fake();
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $req = $this->seedRequest(locale: 'en', email: 'school-en@example.com');

        $this->delete(route('panel_admin.free-reservation-requests.reject', ['freeReservationRequest' => $req->id], false), [
            'reason' => 'The submitted documents are not sufficient.',
        ])->assertRedirect();

        Mail::assertSent(FreeReservationRequestRejectedMail::class, function (FreeReservationRequestRejectedMail $mail): bool {
            $html = $mail->render();

            return $mail->hasTo('school-en@example.com')
                && $mail->envelope()->subject === 'Free reservation request was rejected'
                && str_contains($html, 'Your free reservation request was rejected.')
                && str_contains($html, 'Rejection reason')
                && str_contains($html, 'The submitted documents are not sufficient.')
                && str_contains($html, 'bus.kotor.me')
                && str_contains($html, 'Free reservations');
        });
    }

    public function test_email_failure_does_not_undo_the_rejection(): void
    {
        Queue::fake();
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $req = $this->seedRequest();
        $reason = 'Kapacitet za traženi datum nije slobodan.';

        $this->delete(route('panel_admin.free-reservation-requests.reject', ['freeReservationRequest' => $req->id], false), [
            'reason' => $reason,
        ])->assertRedirect();

        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_REJECTED, $req->status);

        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('mail fail'));

        $thrown = null;
        try {
            (new SendFreeReservationRequestRejectedMailJob((int) $req->id))->handle();
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertSame('mail fail', $thrown?->getMessage());

        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_REJECTED, $req->status);
        $this->assertSame($reason, $req->rejection_reason);
        $this->assertSame($admin->id, (int) $req->rejected_by_admin_id);
        $this->assertNull($req->rejection_notification_sent_at);

        (new SendFreeReservationRequestRejectedMailJob((int) $req->id))->failed(new RuntimeException('mail fail'));
        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_REJECTED, $req->status);
        $this->assertNull($req->rejection_notification_sent_at);
    }

    public function test_repeated_rejection_does_not_send_a_duplicate_email(): void
    {
        Queue::fake();
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $req = $this->seedRequest();

        $this->delete(route('panel_admin.free-reservation-requests.reject', ['freeReservationRequest' => $req->id], false), [
            'reason' => 'Prvo obrazloženje odbijanja.',
        ])->assertRedirect();

        $this->delete(route('panel_admin.free-reservation-requests.reject', ['freeReservationRequest' => $req->id], false), [
            'reason' => 'Drugo obrazloženje ne smije zamijeniti prvo.',
        ])->assertSessionHasErrors('status');

        $req->refresh();
        $this->assertSame('Prvo obrazloženje odbijanja.', $req->rejection_reason);
        $this->assertSame(FreeReservationRequest::STATUS_REJECTED, $req->status);
        Queue::assertPushed(SendFreeReservationRequestRejectedMailJob::class, 1);

        Mail::fake();
        (new SendFreeReservationRequestRejectedMailJob((int) $req->id))->handle();
        $req->refresh();
        $this->assertNotNull($req->rejection_notification_sent_at);
        Mail::assertSent(FreeReservationRequestRejectedMail::class, 1);

        (new SendFreeReservationRequestRejectedMailJob((int) $req->id))->handle();
        Mail::assertSent(FreeReservationRequestRejectedMail::class, 1);
    }

    public function test_fulfilled_request_cannot_be_rejected(): void
    {
        Queue::fake();
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $req = $this->seedRequest(status: FreeReservationRequest::STATUS_FULFILLED);

        $this->delete(route('panel_admin.free-reservation-requests.reject', ['freeReservationRequest' => $req->id], false), [
            'reason' => 'Ovo ne smije proći.',
        ])->assertSessionHasErrors('status');

        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_FULFILLED, $req->status);
        $this->assertNull($req->rejection_reason);
        $this->assertNull($req->rejected_by_admin_id);
        Queue::assertNotPushed(SendFreeReservationRequestRejectedMailJob::class);
    }

    public function test_guest_cannot_reject_a_request(): void
    {
        $req = $this->seedRequest();

        $this->delete(route('panel_admin.free-reservation-requests.reject', ['freeReservationRequest' => $req->id], false), [
            'reason' => 'Neovlašćen pokušaj.',
        ])->assertRedirect(route('panel_admin.login', [], false));

        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_SUBMITTED, $req->status);
    }

    public function test_agency_user_cannot_reject_a_request(): void
    {
        $user = User::factory()->create();
        $req = $this->seedRequest();
        $this->actingAs($user);

        $this->delete(route('panel_admin.free-reservation-requests.reject', ['freeReservationRequest' => $req->id], false), [
            'reason' => 'Agencija ne smije odbiti zahtjev.',
        ])->assertRedirect(route('panel_admin.login', [], false));

        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_SUBMITTED, $req->status);
    }

    public function test_control_only_admin_cannot_reject_a_request(): void
    {
        $admin = Admin::query()->create([
            'username' => 'controlonlyreject',
            'email' => 'control-only-reject@example.com',
            'password' => bcrypt('secret-password-fr'),
            'control_access' => true,
            'admin_access' => false,
        ]);
        $req = $this->seedRequest();
        $this->actingAs($admin, 'panel_admin');

        $this->delete(route('panel_admin.free-reservation-requests.reject', ['freeReservationRequest' => $req->id], false), [
            'reason' => 'Kontrola ne smije odbiti zahtjev.',
        ])->assertForbidden();

        $req->refresh();
        $this->assertSame(FreeReservationRequest::STATUS_SUBMITTED, $req->status);
    }

    public function test_admin_review_of_rejected_requests_shows_the_justification(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', 'Europe/Podgorica'));
        $admin = $this->seedAdmin();
        $this->actingAs($admin, 'panel_admin');
        $req = $this->seedRequest(status: FreeReservationRequest::STATUS_REJECTED);
        $req->update([
            'rejection_reason' => "Kapacitet je popunjen.\nIzaberite drugi datum.",
            'rejected_by_admin_id' => $admin->id,
            'rejected_at' => now(),
        ]);

        $this->get(route('panel_admin.free-reservations', [
            'fzbr_review' => 'rejected',
            'fzbr_date_from' => '2026-10-13',
            'fzbr_date_to' => '2026-10-13',
        ], false))
            ->assertOk()
            ->assertSee('Razlog odbijanja', false)
            ->assertSee('Kapacitet je popunjen.', false)
            ->assertSee('Izaberite drugi datum.', false)
            ->assertDontSee((string) $admin->email, false);
    }

    private function seedAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'rejectadmin',
            'email' => 'reject-admin@example.com',
            'password' => bcrypt('secret-password-fr'),
            'control_access' => false,
            'admin_access' => true,
        ]);
    }

    private function seedRequest(
        string $status = FreeReservationRequest::STATUS_SUBMITTED,
        string $locale = 'cg',
        string $email = 'school@example.com',
    ): FreeReservationRequest {
        $slotA = ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
        $slotB = ListOfTimeSlot::query()->create(['time_slot' => '11:00 - 11:20']);
        $date = Carbon::now()->addDays(4)->toDateString();

        $req = FreeReservationRequest::query()->create([
            'locale' => $locale,
            'institution_name' => 'Škola Primjer',
            'institution_email' => $email,
            'institution_phone' => '+38267111222',
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'country' => 'ME',
            'status' => $status,
        ]);

        FreeReservationRequestSegment::query()->create([
            'request_id' => $req->id,
            'reservation_date' => $date,
            'drop_off_time_slot_id' => $slotA->id,
            'pick_up_time_slot_id' => $slotB->id,
            'position' => 1,
        ]);

        return $req;
    }
}
