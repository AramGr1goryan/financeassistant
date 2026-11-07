<?php

namespace App\Domain\Transactions;

use App\Domain\Accounts\BalanceCalculator;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class TransactionProcessor
{
    public function __construct(
        private readonly TransactionNormalizer $normalizer,
        private readonly TransactionValidator $validator,
        private readonly BalanceCalculator $balanceCalculator,
    ) {}

    /**
     * The input is provider-independent canonical data; provider adapters normalize their payloads before this boundary.
     *
     * @param  array<string, mixed>  $record
     */
    public function process(Account $account, array $record): TransactionProcessingResult
    {
        try {
            $normalized = $this->normalizer->normalize($record);

            return DB::transaction(function () use ($account, $normalized): TransactionProcessingResult {
                $accountIds = [$account->getKey()];

                if ($normalized->transferCounterpartAccountId !== null) {
                    $accountIds[] = $normalized->transferCounterpartAccountId;
                }

                $lockedAccounts = Account::query()
                    ->whereIn('id', array_unique($accountIds))
                    ->where('owner_id', $account->owner_id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $account = $lockedAccounts->get($account->getKey());

                if ($account === null) {
                    throw new ModelNotFoundException('Transaction account no longer exists.');
                }

                $this->validator->validate($normalized, $account);

                $existing = Transaction::query()
                    ->where('account_id', $account->getKey())
                    ->where(fn (Builder $query) => $query
                        ->where('idempotency_key', $normalized->idempotencyKey)
                        ->when(
                            $normalized->externalBankTransactionId !== null,
                            fn (Builder $query) => $query->orWhere(
                                'external_bank_transaction_id',
                                $normalized->externalBankTransactionId,
                            ),
                        )
                        ->when(
                            $normalized->transferReference !== null,
                            fn (Builder $query) => $query->orWhere('transfer_reference', $normalized->transferReference),
                        ))
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null
                    && $existing->external_bank_transaction_id !== null
                    && $normalized->externalBankTransactionId !== null
                    && $existing->external_bank_transaction_id !== $normalized->externalBankTransactionId) {
                    throw new TransactionProcessingException(
                        'deduplicate',
                        'external_id_conflict',
                        'An idempotency key cannot be reused for a different external transaction.',
                        $normalized->idempotencyKey,
                    );
                }

                if ($existing !== null) {
                    $this->assertStatusTransitionIsAllowed($existing, $normalized->status, $normalized->idempotencyKey);
                }

                if ($existing !== null && ! $existing->is_generated_counterpart) {
                    $this->assertReimportIsConsistent($existing, $normalized);
                }

                if ($existing !== null && $existing->getKey() === $normalized->originalTransactionId) {
                    throw new TransactionProcessingException(
                        'validate',
                        'self_refund',
                        'A transaction cannot reference itself as its original refund transaction.',
                        $normalized->idempotencyKey,
                    );
                }

                $transferGroupId = $existing?->transfer_group_id;
                $counterpart = null;

                if ($transferGroupId !== null) {
                    if ($existing->type !== TransactionType::Transfer
                        || $existing->transfer_reference !== $normalized->transferReference) {
                        throw new TransactionProcessingException(
                            'detect_internal_transfer',
                            'transfer_reference_conflict',
                            'An existing internal transfer must retain its type and stable transfer reference.',
                            $normalized->idempotencyKey,
                        );
                    }

                    if ($normalized->transferCounterpartAccountId === null) {
                        throw new TransactionProcessingException(
                            'detect_internal_transfer',
                            'transfer_pair_required',
                            'An existing internal transfer must retain its counterpart account.',
                            $normalized->idempotencyKey,
                        );
                    }

                    $counterpart = Transaction::query()
                        ->where('transfer_group_id', $transferGroupId)
                        ->where('account_id', '!=', $account->getKey())
                        ->first();

                    if ($counterpart !== null && $counterpart->account_id !== $normalized->transferCounterpartAccountId) {
                        throw new TransactionProcessingException(
                            'detect_internal_transfer',
                            'transfer_counterpart_conflict',
                            'An existing transfer cannot be reassigned to another counterpart account.',
                            $normalized->idempotencyKey,
                        );
                    }
                } elseif ($normalized->transferCounterpartAccountId !== null) {
                    $transferGroupId = (string) Str::uuid();
                }

                $attributes = $this->transactionAttributes(
                    $account,
                    $normalized,
                    $existing?->idempotency_key ?? $normalized->idempotencyKey,
                    $transferGroupId,
                    $existing,
                );
                $transaction = $existing ?? new Transaction(['account_id' => $account->getKey()]);
                $transaction->fill($attributes);
                $transaction->save();

                if ($normalized->transferCounterpartAccountId !== null && $transferGroupId !== null) {
                    $this->persistCounterpart($counterpart, $normalized, $transferGroupId);
                }

                $balanceSheet = $this->balanceCalculator->calculateForOwner(
                    $account->owner_id,
                    $account->currency,
                    $account->currency_exponent,
                );

                return new TransactionProcessingResult($transaction, $balanceSheet, $existing === null);
            });
        } catch (TransactionProcessingException $exception) {
            Log::warning('Transaction import rejected', [
                'account_id' => $account->getKey(),
                'stage' => $exception->stage,
                'error_code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionAttributes(
        Account $account,
        NormalizedTransaction $normalized,
        string $idempotencyKey,
        ?string $transferGroupId,
        ?Transaction $existing,
    ): array {
        return [
            'account_id' => $account->getKey(),
            'amount_minor_units' => $normalized->amountMinorUnits,
            'currency' => $normalized->currency,
            'currency_exponent' => $normalized->currencyExponent,
            'type' => $normalized->type,
            'status' => $normalized->status,
            'transaction_at' => $normalized->transactionAt,
            'posting_at' => $normalized->postingAt ?? $existing?->posting_at,
            'description' => $normalized->description,
            'merchant' => $normalized->merchant,
            'external_bank_transaction_id' => $normalized->externalBankTransactionId
                ?? $existing?->external_bank_transaction_id,
            'idempotency_key' => $idempotencyKey,
            'category_id' => $normalized->categoryId,
            'original_transaction_id' => $normalized->originalTransactionId,
            'transfer_group_id' => $transferGroupId,
            'transfer_reference' => $normalized->transferReference,
            'transfer_direction' => $normalized->transferDirection,
            'is_generated_counterpart' => false,
            'metadata' => $normalized->metadata,
        ];
    }

    private function assertReimportIsConsistent(Transaction $existing, NormalizedTransaction $normalized): void
    {
        if ($existing->amount_minor_units !== $normalized->amountMinorUnits
            || $existing->currency !== $normalized->currency
            || $existing->currency_exponent !== $normalized->currencyExponent
            || $existing->type !== $normalized->type
            || ! $existing->transaction_at->equalTo($normalized->transactionAt)
            || $existing->description !== $normalized->description
            || $existing->merchant !== $normalized->merchant
            || $existing->category_id !== $normalized->categoryId
            || $existing->original_transaction_id !== $normalized->originalTransactionId
            || $existing->transfer_reference !== $normalized->transferReference
            || $existing->transfer_direction !== $normalized->transferDirection
            || $existing->metadata !== $normalized->metadata) {
            throw new TransactionProcessingException(
                'deduplicate',
                'historical_transaction_conflict',
                'Re-imported transaction data conflicts with the stored financial record; use an explicit correction or reversal.',
                $normalized->idempotencyKey,
            );
        }

        if ($existing->posting_at !== null && $normalized->postingAt !== null
            && ! $existing->posting_at->equalTo($normalized->postingAt)) {
            throw new TransactionProcessingException(
                'deduplicate',
                'posting_date_conflict',
                'A stored posting date cannot be changed by re-import.',
                $normalized->idempotencyKey,
            );
        }

    }

    private function assertStatusTransitionIsAllowed(
        Transaction $existing,
        TransactionStatus $status,
        string $idempotencyKey,
    ): void {
        $allowedTransitions = match ($existing->status) {
            TransactionStatus::Pending => [
                TransactionStatus::Pending,
                TransactionStatus::Posted,
                TransactionStatus::Reversed,
                TransactionStatus::Declined,
            ],
            TransactionStatus::Posted => [TransactionStatus::Posted, TransactionStatus::Reversed],
            TransactionStatus::Reversed => [TransactionStatus::Reversed],
            TransactionStatus::Declined => [TransactionStatus::Declined],
        };

        if (! in_array($status, $allowedTransitions, true)) {
            throw new TransactionProcessingException(
                'deduplicate',
                'invalid_status_transition',
                'Transaction status cannot move from its stored state to the imported state.',
                $idempotencyKey,
            );
        }
    }

    private function persistCounterpart(
        ?Transaction $counterpart,
        NormalizedTransaction $normalized,
        string $transferGroupId,
    ): void {
        $direction = $normalized->transferDirection === TransferDirection::In
            ? TransferDirection::Out
            : TransferDirection::In;
        $accountId = $normalized->transferCounterpartAccountId;

        if ($counterpart !== null) {
            if (! $counterpart->is_generated_counterpart
                && ($counterpart->type !== TransactionType::Transfer
                || $counterpart->amount_minor_units !== $normalized->amountMinorUnits
                || $counterpart->currency !== $normalized->currency
                || $counterpart->currency_exponent !== $normalized->currencyExponent
                || $counterpart->transfer_reference !== $normalized->transferReference
                || $counterpart->transfer_direction !== $direction)) {
                throw new TransactionProcessingException(
                    'detect_internal_transfer',
                    'counterpart_transaction_conflict',
                    'Imported transfer records do not agree on the paired account, amount, currency, direction, or reference.',
                    $normalized->idempotencyKey,
                );
            }

            $this->assertStatusTransitionIsAllowed($counterpart, $normalized->status, $normalized->idempotencyKey);
            $counterpart->status = $normalized->status;

            if ($counterpart->is_generated_counterpart) {
                $counterpart->posting_at = $normalized->postingAt ?? $counterpart->posting_at;
            }

            $counterpart->save();

            return;
        }

        $counterpart ??= new Transaction(['account_id' => $accountId]);
        $counterpart->fill([
            'account_id' => $accountId,
            'amount_minor_units' => $normalized->amountMinorUnits,
            'currency' => $normalized->currency,
            'currency_exponent' => $normalized->currencyExponent,
            'type' => TransactionType::Transfer,
            'status' => $normalized->status,
            'transaction_at' => $normalized->transactionAt,
            'posting_at' => $normalized->postingAt,
            'description' => $normalized->description,
            'merchant' => null,
            'external_bank_transaction_id' => null,
            'idempotency_key' => $counterpart->idempotency_key ?? 'internal-transfer:'.$transferGroupId,
            'category_id' => null,
            'original_transaction_id' => null,
            'transfer_group_id' => $transferGroupId,
            'transfer_reference' => $normalized->transferReference,
            'transfer_direction' => $direction,
            'is_generated_counterpart' => true,
            'metadata' => null,
        ]);
        $counterpart->save();
    }
}
