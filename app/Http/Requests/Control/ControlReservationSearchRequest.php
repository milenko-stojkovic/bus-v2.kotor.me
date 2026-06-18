<?php

namespace App\Http\Requests\Control;

use Illuminate\Foundation\Http\FormRequest;

class ControlReservationSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes'],
            'date' => ['nullable', 'date'],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'string', 'max:255'],
            'vehicle_type_id' => ['nullable', 'integer', 'exists:vehicle_types,id'],
            'license_plate' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'in:paid,free'],
        ];
    }

    public function hasSearchCriteria(): bool
    {
        foreach (['date', 'name', 'email', 'vehicle_type_id', 'license_plate', 'status'] as $key) {
            $v = $this->input($key);
            if ($v !== null && $v !== '') {
                return true;
            }
        }

        return false;
    }

    public function submittedSearch(): bool
    {
        return $this->has('search');
    }
}
