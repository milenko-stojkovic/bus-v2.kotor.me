<?php

namespace App\Support;

use App\Http\Requests\CheckoutReservationRequest;
use App\Models\TempData;
use Illuminate\Support\Facades\Log;

/**
 * Structured checkout audit events on the payments log channel.
 */
final class CheckoutAuditLogger
{
    public static function log(string $event, array $context = [], string $level = 'info'): void
    {
        Log::channel('payments')->{$level}($event, $context);
    }

    /**
     * @param  array{vehicle_id?:int|null,user_name?:string,country?:string,license_plate?:string,vehicle_type_id?:int,email?:string}|null  $snapshot
     * @return array<string, mixed>
     */
    public static function contextFromRequest(CheckoutReservationRequest $request, ?array $snapshot = null): array
    {
        $snapshot ??= [];

        return array_filter([
            'guest' => $request->user() === null,
            'user_id' => $request->user()?->id,
            'email' => $snapshot['email'] ?? $request->input('email'),
            'user_name' => $snapshot['user_name'] ?? $request->input('name'),
            'license_plate' => $snapshot['license_plate'] ?? $request->input('license_plate'),
            'reservation_kind' => $request->resolvedReservationKind(),
            'reservation_date' => $request->input('reservation_date'),
            'drop_off_time_slot_id' => $request->input('drop_off_time_slot_id'),
            'pick_up_time_slot_id' => $request->input('pick_up_time_slot_id'),
            'vehicle_type_id' => $snapshot['vehicle_type_id'] ?? $request->input('vehicle_type_id'),
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @return array<string, mixed>
     */
    public static function contextFromTemp(TempData $temp): array
    {
        return array_filter([
            'guest' => $temp->user_id === null,
            'user_id' => $temp->user_id,
            'email' => $temp->email,
            'user_name' => $temp->user_name,
            'license_plate' => $temp->license_plate,
            'reservation_kind' => $temp->reservation_kind,
            'reservation_date' => $temp->reservation_date?->format('Y-m-d'),
            'drop_off_time_slot_id' => $temp->drop_off_time_slot_id,
            'pick_up_time_slot_id' => $temp->pick_up_time_slot_id,
            'vehicle_type_id' => $temp->vehicle_type_id,
            'merchant_transaction_id' => $temp->merchant_transaction_id,
            'temp_data_id' => $temp->id,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
