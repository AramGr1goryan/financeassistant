<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FinancialCurrencyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'regex:/\A[A-Z]{3}\z/'],
            'currency_exponent' => ['required', 'integer', 'between:0,6'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('currency')
                    && ! $validator->errors()->has('currency_exponent')
                    && $this->input('currency') === 'AMD'
                    && (int) $this->input('currency_exponent') !== 0) {
                    $validator->errors()->add('currency_exponent', 'AMD amounts must use whole drams.');
                }
            },
        ];
    }

    public function currency(): string
    {
        return $this->validated('currency');
    }

    public function currencyExponent(): int
    {
        return (int) $this->validated('currency_exponent');
    }
}
