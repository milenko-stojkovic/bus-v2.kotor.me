<?php

namespace App\Http\Requests\AdminPanel;

use App\Models\AgencyAdvanceTopup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminPanelAdvanceInsightSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'merchant_transaction_id' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'agency_q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([
                AgencyAdvanceTopup::STATUS_PENDING,
                AgencyAdvanceTopup::STATUS_PAID,
                AgencyAdvanceTopup::STATUS_FAILED,
                AgencyAdvanceTopup::STATUS_EXPIRED,
            ])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $from = (string) $this->input('date_from', '');
            $to = (string) $this->input('date_to', '');
            if ($from !== '' && $to !== '' && $from > $to) {
                $v->errors()->add('date_to', 'Datum „do“ mora biti poslije ili jednak datumu „od“.');
            }

            $hasAny = false;
            foreach (['merchant_transaction_id', 'date_from', 'date_to', 'agency_q', 'status'] as $k) {
                if ((string) $this->input($k, '') !== '') {
                    $hasAny = true;
                    break;
                }
            }

            if (! $hasAny) {
                $v->errors()->add('merchant_transaction_id', 'Unesi bar jedan kriterijum za pretragu.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('merchant_transaction_id')) {
            $this->merge(['merchant_transaction_id' => trim((string) $this->input('merchant_transaction_id'))]);
        }
        if ($this->has('agency_q')) {
            $this->merge(['agency_q' => trim((string) $this->input('agency_q'))]);
        }
    }
}
