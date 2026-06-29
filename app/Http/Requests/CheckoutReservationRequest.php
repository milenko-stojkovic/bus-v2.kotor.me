<?php

namespace App\Http\Requests;

use App\Models\Vehicle;
use App\Services\Reservation\ReservationVehicleEligibilityService;
use App\Support\CheckoutAuditLogger;
use App\Support\ReservationKind;
use App\Support\UiText;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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
        $guestBooking = $authUser === null;
        if ($panelAuthBooking || $guestBooking) {
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
        $guestBooking = $authUser === null;

        return [
            // Opciono: ako frontend pošalje, koristi se za dupli klik; inače backend generiše UUID
            'merchant_transaction_id' => ['nullable', 'string', 'max:64'],
            'payment_method' => [
                $panelAuthBooking ? 'nullable' : 'prohibited',
                'string',
                'in:card,advance',
            ],
            'auth_panel_booking' => ['nullable', 'boolean'],
            'reservation_kind' => ($panelAuthBooking || $guestBooking)
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
            'accept_privacy' => $isDailyTicket
                ? ['prohibited']
                : ['required', 'accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $authUser = $this->user();
            $panelAuthBooking = $authUser !== null && $this->boolean('auth_panel_booking');

            if ($panelAuthBooking) {
                $method = $this->input('payment_method');
                $method = is_string($method) ? trim($method) : '';
                if ($method !== '' && $method === 'advance' && ! (bool) config('features.advance_payments')) {
                    $locale = app()->getLocale();
                    $validator->errors()->add(
                        'payment_method',
                        UiText::t('panel', 'advance_payment_unavailable', 'Advance payment is currently not available.', $locale)
                    );
                }
            }

            $kind = $this->resolvedReservationKind();
            if ($kind !== ReservationKind::TIME_SLOTS) {
                return;
            }

            $eligibility = app(ReservationVehicleEligibilityService::class);
            $locale = app()->getLocale();
            $message = UiText::t(
                'panel',
                'booking_vehicle_type_not_allowed_time_slots',
                $locale === 'cg'
                    ? 'Ova kategorija vozila nije dostupna za rezervacije po terminima. Za putnička vozila (4+1–7+1) koristite dnevnu naknadu.'
                    : 'This vehicle category is not available for time-slot bookings. For passenger vehicles (4+1–7+1), use Daily fee.',
                $locale,
            );

            $vehicleId = $this->input('vehicle_id');
            if ($panelAuthBooking && is_numeric($vehicleId)) {
                $vehicle = Vehicle::query()
                    ->where('user_id', $authUser?->id)
                    ->find((int) $vehicleId);
                if ($vehicle !== null && ! $eligibility->isVehicleTypeAllowedForKind((int) $vehicle->vehicle_type_id, $kind)) {
                    $validator->errors()->add('vehicle_id', $message);
                }

                return;
            }

            $vehicleTypeId = $this->input('vehicle_type_id');
            if (is_numeric($vehicleTypeId)
                && ! $eligibility->isVehicleTypeAllowedForKind((int) $vehicleTypeId, $kind)) {
                $validator->errors()->add('vehicle_type_id', $message);
            }
        });
    }

    public function resolvedReservationKind(): string
    {
        if ($this->isPanelAuthBooking() || $this->user() === null) {
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

    protected function failedValidation(Validator $validator): void
    {
        CheckoutAuditLogger::log('checkout_validation_failed', array_filter([
            'guest' => $this->user() === null,
            'user_id' => $this->user()?->id,
            'email' => $this->input('email'),
            'license_plate' => $this->input('license_plate'),
            'reservation_kind' => $this->input('reservation_kind'),
            'reservation_date' => $this->input('reservation_date'),
            'error_fields' => array_keys($validator->errors()->toArray()),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []), 'warning');

        parent::failedValidation($validator);
    }
}
