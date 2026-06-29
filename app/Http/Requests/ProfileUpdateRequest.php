<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\BankartBillingCountry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! filled($this->input('password'))) {
            $this->merge([
                'password' => null,
                'password_confirmation' => null,
                'current_password' => null,
            ]);
        }

        if ($this->has('country') && is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper(trim($this->input('country')))]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $message = BankartBillingCountry::selectionValidationMessage(
            is_string($this->user()?->lang) ? $this->user()->lang : null,
        );

        return [
            'country.required' => $message,
            'country.in' => $message,
        ];
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $passwordRules = ['nullable', 'string', 'confirmed', Password::defaults()];
        $currentPasswordRules = ['nullable', 'string'];

        if (filled($this->input('password'))) {
            $passwordRules = ['required', 'string', 'confirmed', Password::defaults()];
            $currentPasswordRules = ['required', 'current_password'];
        }

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'lang' => ['required', 'string', Rule::in(['cg', 'en'])],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'country' => [
                'required',
                'string',
                'max:100',
                Rule::in(BankartBillingCountry::selectableCountryCodes()),
            ],
            'current_password' => $currentPasswordRules,
            'password' => $passwordRules,
        ];
    }
}
