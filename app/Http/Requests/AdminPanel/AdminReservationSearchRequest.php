<?php

namespace App\Http\Requests\AdminPanel;

use App\Services\AdminPanel\Reservation\AdminReservationDateBounds;
use App\Support\ReservationKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminReservationSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return self::rulesForSearch();
    }

    /**
     * @return array<string, mixed>
     */
    public static function rulesForSearch(): array
    {
        $bounds = app(AdminReservationDateBounds::class);
        $min = $bounds->searchMinDate()->toDateString();
        $max = $bounds->searchMaxDate()->toDateString();

        return [
            'merchant_transaction_id' => ['nullable', 'string', 'max:64'],
            'use_interval' => ['nullable', 'boolean'],
            'date_single' => ['nullable', 'date', 'after_or_equal:'.$min, 'before_or_equal:'.$max],
            'date_from' => ['nullable', 'date', 'after_or_equal:'.$min, 'before_or_equal:'.$max],
            'date_to' => ['nullable', 'date', 'after_or_equal:'.$min, 'before_or_equal:'.$max],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'vehicle_type_id' => ['nullable', 'integer', 'exists:vehicle_types,id'],
            'license_plate' => ['nullable', 'string', 'max:32'],
            'country' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:paid,free'],
            'agency_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'reservation_kind' => ['nullable', 'string', Rule::in(ReservationKind::ALL)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->boolean('use_interval')) {
                $from = $this->input('date_from');
                $to = $this->input('date_to');
                if ($from && $to && $from >= $to) {
                    $v->errors()->add('date_to', 'Krajnji datum mora biti poslije početnog.');
                }
            }
        });
    }
}
