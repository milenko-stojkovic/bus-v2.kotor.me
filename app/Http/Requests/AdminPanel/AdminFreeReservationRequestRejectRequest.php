<?php

namespace App\Http\Requests\AdminPanel;

use Illuminate\Foundation\Http\FormRequest;

class AdminFreeReservationRequestRejectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('panel_admin') !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('reason')) {
            $this->merge([
                'reason' => trim((string) $this->input('reason')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Razlog odbijanja je obavezan.',
            'reason.string' => 'Razlog odbijanja mora biti tekst.',
            'reason.min' => 'Razlog odbijanja mora imati najmanje :min znaka.',
            'reason.max' => 'Razlog odbijanja može imati najviše :max znakova.',
        ];
    }
}
