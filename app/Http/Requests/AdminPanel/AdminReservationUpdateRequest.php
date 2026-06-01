<?php

namespace App\Http\Requests\AdminPanel;

use App\Models\Reservation;
use App\Models\VehicleType;
use App\Services\AdminPanel\Reservation\AdminReservationDateBounds;
use App\Services\Reservation\PanelReservationListService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminReservationUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reservation = $this->route('reservation');
        if ($reservation instanceof Reservation && PanelReservationListService::isRealized($reservation)) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        $reservation = $this->route('reservation');
        if ($reservation instanceof Reservation && $reservation->isDailyTicket()) {
            return $this->dailyTicketRules();
        }

        return $this->timeSlotsRules();
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedFieldRules(): array
    {
        $bounds = app(AdminReservationDateBounds::class);
        $min = $bounds->editMinDate()->toDateString();
        $max = $bounds->editMaxDate()->toDateString();
        $countries = array_keys((array) config('countries', []));

        return [
            'reservation_date' => ['required', 'date', 'after_or_equal:'.$min, 'before_or_equal:'.$max],
            'user_name' => ['required', 'string', 'max:255', 'regex:/^(?=.*[\p{L}\p{N}]).+$/u'],
            'country' => ['required', 'string', 'max:100', Rule::in($countries)],
            'license_plate' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9]+$/'],
            'vehicle_type_id' => ['required', 'integer', 'exists:vehicle_types,id'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'return_query' => ['nullable', 'string', 'max:2000'],
            'reservation_kind' => ['prohibited'],
            'status' => ['prohibited'],
            'merchant_transaction_id' => ['prohibited'],
            'invoice_amount' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function timeSlotsRules(): array
    {
        return array_merge($this->sharedFieldRules(), [
            'drop_off_time_slot_id' => ['required', 'integer', 'exists:list_of_time_slots,id'],
            'pick_up_time_slot_id' => ['required', 'integer', 'exists:list_of_time_slots,id'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dailyTicketRules(): array
    {
        return array_merge($this->sharedFieldRules(), [
            'drop_off_time_slot_id' => ['prohibited'],
            'pick_up_time_slot_id' => ['prohibited'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $reservation = $this->route('reservation');
            if (! $reservation) {
                return;
            }
            $vtId = (int) $this->input('vehicle_type_id');
            $vt = VehicleType::query()->find($vtId);
            $current = $reservation->vehicleType;
            if ($vt && $current) {
                if ((float) $vt->price > (float) $current->price) {
                    $v->errors()->add('vehicle_type_id', 'Nije dozvoljena veća kategorija od trenutne.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('license_plate')) {
            $this->merge([
                'license_plate' => strtoupper(preg_replace('/\s+/', '', (string) $this->input('license_plate'))),
            ]);
        }
        if ($this->has('user_name')) {
            $this->merge([
                'user_name' => trim((string) $this->input('user_name')),
            ]);
        }
    }
}
