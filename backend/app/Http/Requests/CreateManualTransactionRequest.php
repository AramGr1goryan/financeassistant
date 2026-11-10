<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateManualTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'account_id' => ['required', 'uuid'],
            'amount_minor_units' => ['required', 'string', 'regex:/\A-?(?:0|[1-9][0-9]*)\z/', 'max:20'],
            'type' => ['required', Rule::in(['INCOME', 'EXPENSE', 'TRANSFER', 'REFUND', 'FEE', 'ADJUSTMENT'])],
            'status' => ['required', Rule::in(['PENDING', 'POSTED'])],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:255'],
            'merchant' => ['nullable', 'string', 'max:255'],
            'transfer_direction' => ['nullable', Rule::in(['IN', 'OUT'])],
            'transfer_counterpart_account_id' => ['nullable', 'uuid'],
            'original_transaction_id' => ['nullable', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('transaction_date')
                    && str_starts_with((string) $this->input('transaction_date'), '0000-')) {
                    $validator->errors()->add('transaction_date', 'Transaction date year must be at least 0001.');
                }

                if ($validator->errors()->has('amount_minor_units')) {
                    return;
                }

                $amount = $this->input('amount_minor_units');
                $type = $this->input('type');
                $digits = ltrim($amount, '-');
                $max = (string) PHP_INT_MAX;

                if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
                    $validator->errors()->add('amount_minor_units', 'The amount exceeds the supported integer range.');

                    return;
                }

                if (($type === 'ADJUSTMENT' && $amount === '0')
                    || ($type !== 'ADJUSTMENT' && ($amount === '0' || str_starts_with($amount, '-')))) {
                    $validator->errors()->add('amount_minor_units', 'Enter a positive amount; adjustments must be non-zero and may be signed.');
                }

                if ($type === 'TRANSFER'
                    && (! $this->filled('transfer_direction') || ! $this->filled('transfer_counterpart_account_id'))) {
                    $validator->errors()->add('transfer_counterpart_account_id', 'Internal transfers require a direction and a second account.');
                }

                if ($type !== 'TRANSFER'
                    && ($this->filled('transfer_direction') || $this->filled('transfer_counterpart_account_id'))) {
                    $validator->errors()->add('transfer_counterpart_account_id', 'Transfer details are only valid for transfers.');
                }
            },
        ];
    }
}
