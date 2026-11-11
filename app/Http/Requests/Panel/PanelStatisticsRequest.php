<?php

namespace App\Http\Requests\Panel;

use App\Services\Reservation\AgencyLicensePlateReportService;
use App\Support\UiText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PanelStatisticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'report' => ['nullable', 'in:statistics,financial_card,license_plate'],
            'license_plate' => [
                'nullable',
                'string',
                'max:50',
                Rule::requiredIf(fn (): bool => $this->input('report') === 'license_plate'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($this->input('report') !== 'license_plate' || ! is_string($value) || $value === '') {
                        return;
                    }

                    $user = $this->user();
                    if ($user === null || ! app(AgencyLicensePlateReportService::class)->ownsPlate($user, $value)) {
                        $fail($this->plateMessage('plate_invalid', 'Choose a license plate from this agency\'s reservations.', 'Izaberite registarsku tablicu sa rezervacija ove agencije.'));
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'license_plate.required' => $this->plateMessage('plate_required', 'Choose a license plate.', 'Izaberite registarsku tablicu.'),
        ];
    }

    private function plateMessage(string $key, string $en, string $cg): string
    {
        $locale = app()->getLocale();

        return UiText::t('statistics', $key, $locale === 'en' ? $en : $cg, $locale);
    }
}

