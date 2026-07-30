<?php

namespace App\Services;

use App\Models\AdminAlert;
use App\Models\PostFiscalizationData;
use App\Models\Reservation;
use App\Models\TempData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminFiscalizationAlertService
{
    public const ADMIN_EMAIL = 'bus@kotor.me';

    /**
     * Primalac operativnih alerta (fiskal, kontradiktorni payment ishod).
     */
    public function operationsAlertRecipient(): string
    {
        $configured = config('payment.operations_alert_email');

        return is_string($configured) && $configured !== ''
            ? $configured
            : self::ADMIN_EMAIL;
    }

    /**
     * Banka je poslala SUCCESS dok je temp_data već canceled — bez promene stanja; obaveštenje za ručnu obradu.
     *
     * @param  array<string, mixed>  $incomingRawPayload
     */
    public function notifyPaymentSuccessAfterCanceled(TempData $temp, array $incomingRawPayload): void
    {
        $subject = '[Kotor Bus] Contradictory bank outcome: SUCCESS after canceled payment';

        $reservationId = Reservation::query()
            ->where('merchant_transaction_id', $temp->merchant_transaction_id)
            ->value('id');

        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
        $storedPayload = $temp->raw_callback_payload;
        $storedJson = json_encode(is_array($storedPayload) ? $storedPayload : [], $jsonFlags);
        $incomingJson = json_encode($incomingRawPayload, $jsonFlags);

        $lines = [
            'Incident: The bank/gateway sent SUCCESS after this checkout was already recorded as CANCEL/ERROR (temp_data.status = canceled).',
            'The application did NOT create a reservation, did NOT apply late_success, and did NOT change temp_data.status.',
            'Resolve manually outside the app (bank, customer, reconciliation).',
            '',
            '--- temp_data (investigation) ---',
            'merchant_transaction_id: '.($temp->merchant_transaction_id ?? '—'),
            'temp_data.id: '.$temp->id,
            'status: '.($temp->status ?? '—'),
            'resolution_reason: '.($temp->resolution_reason ?? '—'),
            'callback_error_code: '.($temp->callback_error_code ?? '—'),
            'callback_error_reason: '.($temp->callback_error_reason ?? '—'),
            'user_id: '.($temp->user_id !== null ? (string) $temp->user_id : '—'),
            'user_name: '.($temp->user_name ?? '—'),
            'email: '.($temp->email ?? '—'),
            'country: '.($temp->country ?? '—'),
            'license_plate: '.($temp->license_plate ?? '—'),
            'vehicle_type_id: '.($temp->vehicle_type_id !== null ? (string) $temp->vehicle_type_id : '—'),
            'reservation_date: '.($temp->reservation_date?->format('Y-m-d') ?? '—'),
            'drop_off_time_slot_id: '.($temp->drop_off_time_slot_id !== null ? (string) $temp->drop_off_time_slot_id : '—'),
            'pick_up_time_slot_id: '.($temp->pick_up_time_slot_id !== null ? (string) $temp->pick_up_time_slot_id : '—'),
            'retry_token: '.($temp->retry_token ?? '—'),
            'created_at: '.($temp->created_at?->toIso8601String() ?? '—'),
            'updated_at: '.($temp->updated_at?->toIso8601String() ?? '—'),
            'reservation_id (if any for this merchant tx): '.($reservationId !== null ? (string) $reservationId : '—'),
            '',
            'raw_callback_payload (stored on temp_data):',
            $storedJson,
            '',
            'incoming callback/inquiry raw payload:',
            $incomingJson !== false ? $incomingJson : '{}',
        ];
        $body = implode("\n", $lines);

        AdminAlert::query()->create([
            'type' => 'payment_success_after_canceled',
            'status' => AdminAlert::STATUS_UNREAD,
            'title' => 'Contradictory bank outcome: SUCCESS after canceled payment',
            'message' => sprintf(
                'Banka je poslala SUCCESS dok je temp_data #%d već canceled (merchant_transaction_id: %s). Rešavanje je van aplikacije.',
                $temp->id,
                $temp->merchant_transaction_id ?? '—'
            ),
            'payload_json' => [
                'email_full_body' => $body,
                'incoming_raw_payload' => $incomingRawPayload,
                'stored_raw_callback_payload' => is_array($storedPayload) ? $storedPayload : null,
            ],
            'merchant_transaction_id' => $temp->merchant_transaction_id,
            'temp_data_id' => $temp->id,
            'reservation_id' => $reservationId,
        ]);

        $this->notify($subject, $body, [
            'alert_type' => 'payment_success_after_canceled',
            'merchant_transaction_id' => $temp->merchant_transaction_id,
            'temp_data_id' => $temp->id,
        ]);
    }

    /**
     * Guest payment SUCCESS after pending expire → late_success (no reservation). Staff must Force or Reject.
     *
     * @param  array<string, mixed>  $incomingRawPayload
     */
    public function notifyGuestLateSuccess(TempData $temp, array $incomingRawPayload = []): void
    {
        if ($temp->user_id !== null) {
            return;
        }

        $subject = '[Kotor Bus] Late SUCCESS: guest payment after expiration — review required';

        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
        $storedPayload = $temp->raw_callback_payload;
        $storedJson = json_encode(is_array($storedPayload) ? $storedPayload : [], $jsonFlags);
        $incomingJson = json_encode($incomingRawPayload, $jsonFlags);

        $staffUrl = url('/staff/late-success/'.$temp->id);

        $lines = [
            'Incident: Bank SUCCESS arrived after this checkout pending had already expired (temp_data → late_success).',
            'Payment succeeded, but a reservation was NOT created automatically (slot lock was released at expire).',
            'Administrator review is required: Force reservation or Reject at '.$staffUrl,
            '',
            '--- temp_data (investigation) ---',
            'merchant_transaction_id: '.($temp->merchant_transaction_id ?? '—'),
            'temp_data.id: '.$temp->id,
            'status: '.($temp->status ?? '—'),
            'user_id: — (guest)',
            'user_name: '.($temp->user_name ?? '—'),
            'email: '.($temp->email ?? '—'),
            'country: '.($temp->country ?? '—'),
            'license_plate: '.($temp->license_plate ?? '—'),
            'vehicle_type_id: '.($temp->vehicle_type_id !== null ? (string) $temp->vehicle_type_id : '—'),
            'reservation_date: '.($temp->reservation_date?->format('Y-m-d') ?? '—'),
            'drop_off_time_slot_id: '.($temp->drop_off_time_slot_id !== null ? (string) $temp->drop_off_time_slot_id : '—'),
            'pick_up_time_slot_id: '.($temp->pick_up_time_slot_id !== null ? (string) $temp->pick_up_time_slot_id : '—'),
            'retry_token: '.($temp->retry_token ?? '—'),
            'created_at: '.($temp->created_at?->toIso8601String() ?? '—'),
            'updated_at: '.($temp->updated_at?->toIso8601String() ?? '—'),
            '',
            'raw_callback_payload (stored on temp_data):',
            $storedJson !== false ? $storedJson : '{}',
            '',
            'incoming callback/inquiry raw payload:',
            $incomingJson !== false ? $incomingJson : '{}',
        ];
        $body = implode("\n", $lines);

        AdminAlert::query()->create([
            'type' => 'guest_late_success',
            'status' => AdminAlert::STATUS_UNREAD,
            'title' => 'Guest Late SUCCESS after expiration — review required',
            'message' => sprintf(
                'Guest payment succeeded after expire (temp_data #%d, MTID %s). Reservation was NOT created. Open Staff Late Success to Force or Reject.',
                $temp->id,
                $temp->merchant_transaction_id ?? '—'
            ),
            'payload_json' => [
                'email_full_body' => $body,
                'staff_url' => $staffUrl,
                'incoming_raw_payload' => $incomingRawPayload,
                'stored_raw_callback_payload' => is_array($storedPayload) ? $storedPayload : null,
            ],
            'merchant_transaction_id' => $temp->merchant_transaction_id,
            'temp_data_id' => $temp->id,
            'reservation_id' => null,
        ]);

        $this->notify($subject, $body, [
            'alert_type' => 'guest_late_success',
            'merchant_transaction_id' => $temp->merchant_transaction_id,
            'temp_data_id' => $temp->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function notify(string $subject, string $body, array $context = []): void
    {
        $fromAddress = config('mail.from.address');
        $fromName = config('mail.from.name');
        $to = $this->operationsAlertRecipient();

        try {
            Mail::raw($body, function ($message) use ($subject, $fromAddress, $fromName, $to): void {
                $message->to($to)
                    ->from($fromAddress, $fromName)
                    ->subject($subject);
            });

            Log::channel('payments')->warning('Admin fiscalization email sent', [
                'to' => $to,
                'subject' => $subject,
                ...$context,
            ]);
        } catch (\Throwable $e) {
            Log::channel('payments')->error('Admin fiscalization email failed', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
                ...$context,
            ]);
        }
    }

    public function buildReservationContext(Reservation $r): string
    {
        return implode("\n", array_filter([
            'reservation_id: '.$r->id,
            'merchant_transaction_id: '.($r->merchant_transaction_id ?? '—'),
            'date: '.($r->reservation_date?->format('Y-m-d') ?? '—'),
            'drop_off_time_slot_id: '.($r->drop_off_time_slot_id ?? '—'),
            'pick_up_time_slot_id: '.($r->pick_up_time_slot_id ?? '—'),
            'user_name: '.($r->user_name ?? '—'),
            'email: '.($r->email ?? '—'),
            'country: '.($r->country ?? '—'),
            'license_plate: '.($r->license_plate ?? '—'),
            'vehicle_type_id: '.($r->vehicle_type_id ?? '—'),
            'status: '.($r->status ?? '—'),
            'fiscal_jir: '.($r->fiscal_jir ?? '—'),
            'fiscal_ikof: '.($r->fiscal_ikof ?? '—'),
            'created_at: '.($r->created_at?->toDateTimeString() ?? '—'),
        ]));
    }

    public function buildPostRowContext(PostFiscalizationData $p): string
    {
        return implode("\n", array_filter([
            'post_fiscalization_data_id: '.$p->id,
            'attempts: '.($p->attempts ?? 0),
            'error: '.($p->error ?? '—'),
            'next_retry_at: '.($p->next_retry_at?->toDateTimeString() ?? '—'),
            'resolved_at: '.($p->resolved_at?->toDateTimeString() ?? '—'),
            'admin_notified_at: '.($p->admin_notified_at?->toDateTimeString() ?? '—'),
            'created_at: '.($p->created_at?->toDateTimeString() ?? '—'),
        ]));
    }
}

