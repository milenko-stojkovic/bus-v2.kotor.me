<?php

namespace App\Http\Requests\AdminPanel;

use Illuminate\Foundation\Http\FormRequest;

class AdminPanelPriceChangeRequest extends FormRequest
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
            'effective_on' => ['required', 'date'],
            'prices' => ['required', 'array'],
            'prices.*' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'effective_on.required' => 'Unesite datum važenja.',
            'effective_on.date' => 'Unesite ispravan datum važenja.',
            'prices.required' => 'Unesite cijene kategorija.',
        ];
    }
}
