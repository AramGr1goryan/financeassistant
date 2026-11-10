<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateIncomeExpectationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'description' => ['required', 'string', 'max:255'],
            'amount_minor_units' => ['required', 'string', 'regex:/\A[1-9][0-9]*\z/', 'max:19'],
            'expected_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('amount_minor_units')) {
                    return;
                }

                $amount = $this->input('amount_minor_units');
                $maximum = (string) PHP_INT_MAX;
                if (strlen($amount) > strlen($maximum) || (strlen($amount) === strlen($maximum) && strcmp($amount, $maximum) > 0)) {
                    $validator->errors()->add('amount_minor_units', 'The amount exceeds the supported integer range.');
                }
            },
        ];
    }
}
