<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveBudgetSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'mandatory_payments' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]*)\z/', 'max:19'],
            'protected_reserve' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]*)\z/', 'max:19'],
            'allocated_savings' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]*)\z/', 'max:19'],
            'planning_days' => ['required', 'integer', 'between:1,2147483647'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (['mandatory_payments', 'protected_reserve', 'allocated_savings'] as $field) {
                    if ($validator->errors()->has($field)) {
                        continue;
                    }

                    $value = $this->input($field);
                    $max = (string) PHP_INT_MAX;
                    if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
                        $validator->errors()->add($field, 'The amount exceeds the supported integer range.');
                    }
                }
            },
        ];
    }
}
