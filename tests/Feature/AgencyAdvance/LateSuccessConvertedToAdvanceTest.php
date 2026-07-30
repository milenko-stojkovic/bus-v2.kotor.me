<?php

namespace Tests\Feature\AgencyAdvance;

use App\Contracts\PaymentService;
use App\Jobs\PaymentCallbackJob;
use App\Mail\AdvanceTopupConfirmationMail;
use App\Models\AgencyAdvanceTopup;
use App\Models\AgencyAdvanceTransaction;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\VehicleTypeTranslation;
use Carbon\Carbon;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

final class LateSuccessConvertedToAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private function seedType(float $price = 12.0): VehicleType
    {
        $t = VehicleType::query()->create(['price' => $price]);
        VehicleTypeTranslation::query()->create(['vehicle_type_id' => $t->id, 'locale' => 'en', 'name' => 'T'.$t->id, 'description' => null]);
        VehicleTypeTranslation::query()->create(['vehicle_type_id' => $t->id, 'locale' => 'cg', 'name' => 'T'.$t->id, 'description' => null]);
        return $t;
    }

    private function seedSlots(): ListOfTimeSlot
    {
        return ListOfTimeSlot::query()->create(['time_slot' => '10:00 - 10:20']);
    }

    public function test_a_agency_late_success_feature_on_converts_to_advance_topup_and_sends_confirmation(): void
    {
        config()->set('features.advance_payments', true);
        Mail::fake();

        $user = User::factory()->create(['email' => 'agency@example.com']);
        $t = $this->seedType(12);
        $slot = $this->seedSlots();

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-late-1',
            'retry_token' => 'rt',
            'user_id' => $user->id,
            'vehicle_id' => null,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => Carbon::now()->addDay()->toDateString(),
            'user_name' => 'U',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $t->id,
            'invoice_amount_snapshot' => '12.00',
            'email' => 'x@example.com',
            'preferred_locale' => 'cg',
            'status' => TempData::STATUS_EXPIRED,
            'resolution_reason' => null,
        ]);

        // Price changed after checkout: conversion must still use the snapshot amount.
        $t->update(['price' => 15]);

        (new PaymentCallbackJob(['merchant_transaction_id' => 'mt-late-1', 'status' => 'success'], ['result' => 'OK']))->handle();

        $temp->refresh();
        $this->assertSame(TempData::STATUS_LATE_SUCCESS, (string) $temp->status);
        $this->assertSame('converted_to_advance', (string) $temp->resolution_reason);

        $this->assertSame(0, Reservation::query()->count());

        $topup = AgencyAdvanceTopup::query()->where('merchant_transaction_id', 'mt-late-1')->firstOrFail();
        $this->assertSame(AgencyAdvanceTopup::STATUS_PAID, (string) $topup->status);
        $this->assertSame('12.00', (string) $topup->amount);

        $ledger = AgencyAdvanceTransaction::query()
            ->where('agency_user_id', $user->id)
            ->where('type', AgencyAdvanceTransaction::TYPE_TOPUP)
            ->where('reference_type', 'late_success_temp_data')
            ->where('reference_id', $temp->id)
            ->firstOrFail();
        $this->assertSame('12.00', (string) $ledger->amount);
        $this->assertSame((string) $topup->amount, (string) $ledger->amount);

        Mail::assertSent(AdvanceTopupConfirmationMail::class, 1);
    }

    public function test_b_guest_late_success_is_not_converted(): void
    {
        config()->set('features.advance_payments', true);
        Mail::fake();

        $t = $this->seedType(10);
        $slot = $this->seedSlots();

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-late-guest',
            'retry_token' => 'rt',
            'user_id' => null,
            'vehicle_id' => null,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => Carbon::now()->addDay()->toDateString(),
            'user_name' => 'U',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $t->id,
            'email' => 'x@example.com',
            'preferred_locale' => 'cg',
            'status' => TempData::STATUS_EXPIRED,
            'resolution_reason' => null,
        ]);

        (new PaymentCallbackJob(['merchant_transaction_id' => 'mt-late-guest', 'status' => 'success'], ['result' => 'OK']))->handle();

        $temp->refresh();
        $this->assertSame(TempData::STATUS_LATE_SUCCESS, (string) $temp->status);
        $this->assertNotSame('converted_to_advance', (string) ($temp->resolution_reason ?? ''));

        $this->assertSame(0, AgencyAdvanceTopup::query()->count());
        $this->assertSame(0, AgencyAdvanceTransaction::query()->count());
        $this->assertNotNull(
            \App\Models\AdminAlert::query()
                ->where('type', 'guest_late_success')
                ->where('merchant_transaction_id', 'mt-late-guest')
                ->first()
        );
    }

    public function test_c_feature_flag_off_does_not_convert(): void
    {
        config()->set('features.advance_payments', false);
        Mail::fake();

        $user = User::factory()->create();
        $t = $this->seedType(10);
        $slot = $this->seedSlots();

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-late-off',
            'retry_token' => 'rt',
            'user_id' => $user->id,
            'vehicle_id' => null,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => Carbon::now()->addDay()->toDateString(),
            'user_name' => 'U',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $t->id,
            'email' => 'x@example.com',
            'preferred_locale' => 'cg',
            'status' => TempData::STATUS_EXPIRED,
            'resolution_reason' => null,
        ]);

        (new PaymentCallbackJob(['merchant_transaction_id' => 'mt-late-off', 'status' => 'success'], ['result' => 'OK']))->handle();

        $temp->refresh();
        $this->assertSame(TempData::STATUS_LATE_SUCCESS, (string) $temp->status);
        $this->assertNotSame('converted_to_advance', (string) ($temp->resolution_reason ?? ''));

        $this->assertSame(0, AgencyAdvanceTopup::query()->count());
        $this->assertSame(0, AgencyAdvanceTransaction::query()->count());
        Mail::assertNothingSent();
    }

    public function test_d_duplicate_callbacks_do_not_duplicate_topup_or_ledger_or_confirmation(): void
    {
        config()->set('features.advance_payments', true);
        Mail::fake();

        $user = User::factory()->create(['email' => 'agency@example.com']);
        $t = $this->seedType(10);
        $slot = $this->seedSlots();

        TempData::query()->create([
            'merchant_transaction_id' => 'mt-late-dupe',
            'retry_token' => 'rt',
            'user_id' => $user->id,
            'vehicle_id' => null,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => Carbon::now()->addDay()->toDateString(),
            'user_name' => 'U',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $t->id,
            'email' => 'x@example.com',
            'preferred_locale' => 'cg',
            'status' => TempData::STATUS_EXPIRED,
            'resolution_reason' => null,
        ]);

        $job1 = new PaymentCallbackJob(['merchant_transaction_id' => 'mt-late-dupe', 'status' => 'success'], ['result' => 'OK']);
        $job2 = new PaymentCallbackJob(['merchant_transaction_id' => 'mt-late-dupe', 'status' => 'success'], ['result' => 'OK']);
        $job1->handle();
        $job2->handle();

        $this->assertSame(1, AgencyAdvanceTopup::query()->where('merchant_transaction_id', 'mt-late-dupe')->count());
        $this->assertSame(1, AgencyAdvanceTransaction::query()->where('merchant_transaction_id', 'mt-late-dupe')->count());
        Mail::assertSent(AdvanceTopupConfirmationMail::class, 1);
    }

    public function test_e_canceled_and_late_success_is_not_applied_and_not_converted(): void
    {
        config()->set('features.advance_payments', true);
        Mail::fake();

        $user = User::factory()->create(['email' => 'agency@example.com']);
        $t = $this->seedType(10);
        $slot = $this->seedSlots();

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-canceled',
            'retry_token' => 'rt',
            'user_id' => $user->id,
            'vehicle_id' => null,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => Carbon::now()->addDay()->toDateString(),
            'user_name' => 'U',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $t->id,
            'email' => 'x@example.com',
            'preferred_locale' => 'cg',
            'status' => TempData::STATUS_CANCELED,
            'resolution_reason' => null,
        ]);

        (new PaymentCallbackJob(['merchant_transaction_id' => 'mt-canceled', 'status' => 'success'], ['result' => 'OK']))->handle();

        $temp->refresh();
        $this->assertSame(TempData::STATUS_CANCELED, (string) $temp->status);
        $this->assertSame(0, AgencyAdvanceTopup::query()->count());
        $this->assertSame(0, AgencyAdvanceTransaction::query()->count());
    }

    public function test_f_mail_failure_does_not_rollback_conversion(): void
    {
        config()->set('features.advance_payments', true);

        $user = User::factory()->create(['email' => 'agency@example.com']);
        $t = $this->seedType(10);
        $slot = $this->seedSlots();

        $temp = TempData::query()->create([
            'merchant_transaction_id' => 'mt-mail-fail',
            'retry_token' => 'rt',
            'user_id' => $user->id,
            'vehicle_id' => null,
            'drop_off_time_slot_id' => $slot->id,
            'pick_up_time_slot_id' => $slot->id,
            'reservation_date' => Carbon::now()->addDay()->toDateString(),
            'user_name' => 'U',
            'country' => 'ME',
            'license_plate' => 'KO1',
            'vehicle_type_id' => $t->id,
            'email' => 'x@example.com',
            'preferred_locale' => 'cg',
            'status' => TempData::STATUS_EXPIRED,
            'resolution_reason' => null,
        ]);

        // Force mailer to throw so confirmation fails.
        $mock = Mockery::mock(Mailer::class);
        $mock->shouldReceive('to')->andReturnSelf();
        $mock->shouldReceive('send')->andThrow(new \RuntimeException('mail fail'));
        $this->app->instance(Mailer::class, $mock);

        (new PaymentCallbackJob(['merchant_transaction_id' => 'mt-mail-fail', 'status' => 'success'], ['result' => 'OK']))->handle();

        $temp->refresh();
        $this->assertSame(TempData::STATUS_LATE_SUCCESS, (string) $temp->status);
        $this->assertSame('converted_to_advance', (string) $temp->resolution_reason);

        $topup = AgencyAdvanceTopup::query()->where('merchant_transaction_id', 'mt-mail-fail')->firstOrFail();
        $this->assertSame(AgencyAdvanceTopup::STATUS_PAID, (string) $topup->status);
        $this->assertNull($topup->confirmation_sent_at);

        $this->assertSame(1, AgencyAdvanceTransaction::query()->where('merchant_transaction_id', 'mt-mail-fail')->count());
    }
}

