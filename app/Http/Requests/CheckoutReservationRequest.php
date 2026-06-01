<?php

namespace App\Http\Requests;

use App\Support\ReservationKind;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CheckoutReservationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        if (is_string($name)) {
            $this->merge(['name' => trim($name)]);
        }

        $license = $this->input('license_plate');
        if (is_string($license)) {
            $normalized = strtoupper(trim($license));
            $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;
            $normalized = preg_replace('/[^A-Z0-9]/', '', $normalized) ?? $normalized;
            $this->merge(['license_plate' => $normalized]);
        }

        $authUser = $this->user();
        $panelAuthBooking = $authUser !== null && $this->boolean('auth_panel_booking');
        if ($panelAuthBooking) {
            $kind = $this->input('reservation_kind');
            if (! is_string($kind) || trim($kind) === '') {
                $this->merge(['reservation_kind' => ReservationKind::TIME_SLOTS]);
            }
        }

        if ($this->resolvedReservationKind() === ReservationKind::DAILY_TICKET) {
            $this->merge([
                'drop_off_time_slot_id' => null,
                'pick_up_time_slot_id' => null,
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $authUser = $this->user();
        $usingSavedVehicle = $authUser !== null && $this->filled('vehicle_id');
        $panelAuthBooking = $authUser !== null && $this->boolean('auth_panel_booking');
        $advanceEnabled = (bool) config('features.advance_payments');
        $isDailyTicket = $this->resolvedReservationKind() === ReservationKind::DAILY_TICKET;

        return [
            // Opciono: ako frontend pošalje, koristi se za dupli klik; inače backend generiše UUID
            'merchant_transaction_id' => ['nullable', 'string', 'max:64'],
            'payment_method' => [
                $panelAuthBooking ? 'nullable' : 'prohibited',
                'string',
                'in:card,advance',
            ],
            'auth_panel_booking' => ['nullable', 'boolean'],
            'reservation_kind' => $panelAuthBooking
                ? ['nullable', 'string', Rule::in(ReservationKind::ALL)]
                : ['prohibited'],
            'vehicle_id' => [
                $panelAuthBooking ? 'required' : 'nullable',
                'integer',
                Rule::exists('vehicles', 'id')->where(fn ($q) => $q->where('user_id', $authUser?->id)),
            ],
            'drop_off_time_slot_id' => $isDailyTicket
                ? ['prohibited']
                : ['required', 'integer', 'exists:list_of_time_slots,id'],
            'pick_up_time_slot_id' => $isDailyTicket
                ? ['prohibited']
                : ['required', 'integer', 'exists:list_of_time_slots,id'],
            'reservation_date' => ['required', 'date', 'after_or_equal:today'],
            'name' => [$usingSavedVehicle ? 'nullable' : 'required', 'string', 'min:2', 'max:255'],
            'country' => [$usingSavedVehicle ? 'nullable' : 'required', 'string', 'max:100'],
            'license_plate' => [
                $usingSavedVehicle ? 'nullable' : 'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9]+$/',
            ],
            'vehicle_type_id' => [$usingSavedVehicle ? 'nullable' : 'required', 'integer', 'exists:vehicle_types,id'],
            'email' => [$usingSavedVehicle ? 'nullable' : 'required', 'email', 'max:255'],

            // Guest UX hard requirements (not persisted; audit/UI later).
            'accept_terms' => ['required', 'accepted'],
            'accept_privacy' => ['required', 'accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $authUser = $this->user();
            $panelAuthBooking = $authUser !== null && $this->boolean('auth_panel_booking');
            if (! $panelAuthBooking) {
                return;
            }

            $method = $this->input('payment_method');
            $method = is_string($method) ? trim($method) : '';
            if ($method === '') {
                return;
            }

            if ($method === 'advance' && ! (bool) config('features.advance_payments')) {
                $validator->errors()->add('payment_method', 'Avansno plaćanje trenutno nije dostupno.');
            }
        });
    }

    public function resolvedReservationKind(): string
    {
        if ($this->user() !== null && $this->boolean('auth_panel_booking')) {
            $kind = $this->input('reservation_kind');

            return is_string($kind) && in_array($kind, ReservationKind::ALL, true)
                ? $kind
                : ReservationKind::TIME_SLOTS;
        }

        return ReservationKind::TIME_SLOTS;
    }

    public function isDailyTicketBooking(): bool
    {
        return $this->resolvedReservationKind() === ReservationKind::DAILY_TICKET;
    }

    public function isPanelAuthBooking(): bool
    {
        return $this->user() !== null && $this->boolean('auth_panel_booking');
    }
}
