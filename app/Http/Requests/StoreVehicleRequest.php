<?php

namespace App\Http\Requests;

use App\Services\Vehicle\VehiclePlateCategoryConsistency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $license = $this->input('license_plate');
        if (is_string($license)) {
            $this->merge([
                'license_plate' => VehiclePlateCategoryConsistency::normalize($license),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'license_plate' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9]+$/',
                Rule::unique('vehicles')->where(fn ($q) => $q
                    ->where('user_id', $this->user()->id)
                    ->where('status', \App\Models\Vehicle::STATUS_ACTIVE)),
            ],
            'vehicle_type_id' => ['required', 'integer', 'exists:vehicle_types,id'],
            'category_choice' => ['nullable', 'string', 'in:use_recorded'],
            'expected_recorded_vehicle_type_id' => ['nullable', 'integer', 'exists:vehicle_types,id'],
        ];
    }
}
