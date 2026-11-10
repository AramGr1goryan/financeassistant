<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['ASSET', 'CREDIT'])],
            'opening_balance' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]*)\z/', 'max:19'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('opening_balance')) {
                    return;
                }

                $value = $this->input('opening_balance');
                $max = (string) PHP_INT_MAX;
                if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
                    $validator->errors()->add('opening_balance', 'The amount exceeds the supported integer range.');
                }
            },
        ];
    }
}
