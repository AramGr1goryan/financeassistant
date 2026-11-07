<?php

namespace App\Domain\Transactions;

use App\Domain\Accounts\AccountType;
use App\Domain\Money\Money;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use Illuminate\Support\Str;

final class TransactionValidator
{
    public function validate(NormalizedTransaction $transaction, Account $account): void
    {
        if ($transaction->idempotencyKey === '' || mb_strlen($transaction->idempotencyKey) > 255) {
            $this->fail('invalid_idempotency_key', 'Idempotency key must be between 1 and 255 characters.', $transaction);
        }

        if ($transaction->externalBankTransactionId !== null && mb_strlen($transaction->externalBankTransactionId) > 255) {
            $this->fail('invalid_external_id', 'External bank transaction ID exceeds 255 characters.', $transaction);
        }

        if ($transaction->merchant !== null && mb_strlen($transaction->merchant) > 255) {
            $this->fail('invalid_merchant', 'Merchant exceeds 255 characters.', $transaction);
        }

        if ($transaction->currency !== $account->currency || $transaction->currencyExponent !== $account->currency_exponent) {
            $this->fail('account_currency_mismatch', 'Transaction currency and exponent must match the account.', $transaction);
        }

        try {
            new Money($transaction->amountMinorUnits, $transaction->currency, $transaction->currencyExponent);
        } catch (\InvalidArgumentException $exception) {
            $this->fail('invalid_amount', $exception->getMessage(), $transaction);
        }

        if ($transaction->type === TransactionType::Adjustment) {
            if ($transaction->amountMinorUnits === 0) {
                $this->fail('zero_adjustment', 'Adjustment amount cannot be zero.', $transaction);
            }
        } elseif ($transaction->amountMinorUnits <= 0) {
            $this->fail('non_positive_amount', 'Transaction amount must be positive except for signed adjustments.', $transaction);
        }

        if ($account->type === AccountType::Credit && $transaction->type === TransactionType::Income) {
            $this->fail('income_on_credit_account', 'Income transactions cannot be posted to a credit liability account.', $transaction);
        }

        if ($transaction->type === TransactionType::Transfer) {
            if ($transaction->transferDirection === null) {
                $this->fail('missing_transfer_direction', 'Transfer direction is required.', $transaction);
            }

            if ($transaction->transferReference !== null && mb_strlen($transaction->transferReference) > 255) {
                $this->fail('invalid_transfer_reference', 'Transfer reference exceeds 255 characters.', $transaction);
            }

            if ($transaction->transferCounterpartAccountId !== null) {
                if ($transaction->transferReference === null) {
                    $this->fail(
                        'missing_transfer_reference',
                        'Internal transfers require a stable reference shared by both account records.',
                        $transaction,
                    );
                }

                $this->validateCounterpart($transaction, $account);
            }
        } elseif ($transaction->transferDirection !== null
            || $transaction->transferCounterpartAccountId !== null
            || $transaction->transferReference !== null) {
            $this->fail('transfer_fields_on_non_transfer', 'Transfer fields are only valid for transfer transactions.', $transaction);
        }

        if ($transaction->categoryId !== null) {
            if (! Str::isUuid($transaction->categoryId) || ! TransactionCategory::query()->whereKey($transaction->categoryId)->exists()) {
                $this->fail('unknown_category', 'Transaction category does not exist.', $transaction);
            }
        }

        if ($transaction->originalTransactionId !== null) {
            $this->validateOriginalTransaction($transaction, $account);
        }

        if ($transaction->metadata !== null) {
            $this->validateMetadata($transaction);
        }
    }

    private function validateCounterpart(NormalizedTransaction $transaction, Account $account): void
    {
        if (! Str::isUuid($transaction->transferCounterpartAccountId)) {
            $this->fail('invalid_transfer_counterpart', 'Transfer counterpart account ID must be a UUID.', $transaction);
        }

        if ($transaction->transferCounterpartAccountId === $account->getKey()) {
            $this->fail('same_transfer_account', 'A transfer must have two distinct accounts.', $transaction);
        }

        $counterpart = Account::query()
            ->whereKey($transaction->transferCounterpartAccountId)
            ->where('owner_id', $account->owner_id)
            ->first();

        if ($counterpart === null) {
            $this->fail('unknown_transfer_counterpart', 'Transfer counterpart must be another account owned by the same user.', $transaction);
        }

        if ($counterpart->currency !== $transaction->currency || $counterpart->currency_exponent !== $transaction->currencyExponent) {
            $this->fail('transfer_currency_mismatch', 'Internal transfers require matching currencies and exponents.', $transaction);
        }
    }

    private function validateOriginalTransaction(NormalizedTransaction $transaction, Account $account): void
    {
        if ($transaction->type !== TransactionType::Refund) {
            $this->fail('original_on_non_refund', 'Only refunds may reference an original transaction.', $transaction);
        }

        if (! Str::isUuid($transaction->originalTransactionId)) {
            $this->fail('invalid_original_transaction', 'Original transaction ID must be a UUID.', $transaction);
        }

        $original = Transaction::query()
            ->whereKey($transaction->originalTransactionId)
            ->whereHas('account', fn ($query) => $query->where('owner_id', $account->owner_id))
            ->first();

        if ($original === null || ! in_array($original->type, [TransactionType::Expense, TransactionType::Fee], true)) {
            $this->fail('invalid_refund_reference', 'Refund reference must identify an expense or fee owned by the same user.', $transaction);
        }

        if ($original->currency !== $transaction->currency || $original->currency_exponent !== $transaction->currencyExponent) {
            $this->fail('refund_currency_mismatch', 'Refund currency and exponent must match the original transaction.', $transaction);
        }
    }

    private function validateMetadata(NormalizedTransaction $transaction): void
    {
        try {
            $encodedMetadata = json_encode($transaction->metadata, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->fail('invalid_metadata', 'Metadata must contain JSON-serializable values.', $transaction);
        }

        if (strlen($encodedMetadata) > 16_384) {
            $this->fail('metadata_too_large', 'Metadata must not exceed 16 KiB.', $transaction);
        }

        if ($this->containsSensitiveKey($transaction->metadata)) {
            $this->fail('sensitive_metadata', 'Sensitive credentials must not be stored in transaction metadata.', $transaction);
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function containsSensitiveKey(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/password|pin|cvv|cvc|secret|access.?token|refresh.?token|card.?number/i', $key) === 1) {
                return true;
            }

            if (is_array($value) && $this->containsSensitiveKey($value)) {
                return true;
            }
        }

        return false;
    }

    private function fail(string $errorCode, string $message, NormalizedTransaction $transaction): never
    {
        throw new TransactionProcessingException('validate', $errorCode, $message, $transaction->idempotencyKey);
    }
}
