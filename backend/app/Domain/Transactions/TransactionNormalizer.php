<?php

namespace App\Domain\Transactions;

use App\Domain\Money\Money;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;

final class TransactionNormalizer
{
    /**
     * @param  array<string, mixed>  $record
     */
    public function normalize(array $record): NormalizedTransaction
    {
        $externalId = $this->optionalVerbatimString($record, 'external_bank_transaction_id');
        $idempotencyKey = $this->optionalString($record, 'idempotency_key') ?? $externalId;
        $amount = $this->integer($record, 'amount_minor_units', $idempotencyKey);
        $currency = strtoupper(trim($this->requiredString($record, 'currency', $idempotencyKey)));
        $currencyExponent = $this->integer($record, 'currency_exponent', $idempotencyKey);

        try {
            new Money($amount, $currency, $currencyExponent);
        } catch (\InvalidArgumentException $exception) {
            throw new TransactionProcessingException('normalize', 'invalid_money', $exception->getMessage(), $idempotencyKey);
        }

        $type = $this->enumValue(TransactionType::class, $record, 'type', $idempotencyKey);
        $status = $this->enumValue(TransactionStatus::class, $record, 'status', $idempotencyKey);
        $direction = $this->optionalEnumValue(TransferDirection::class, $record, 'transfer_direction', $idempotencyKey);
        $metadata = $record['metadata'] ?? null;

        if ($metadata !== null && ! is_array($metadata)) {
            throw new TransactionProcessingException('normalize', 'invalid_metadata', 'Metadata must be an object or null.', $idempotencyKey);
        }

        $transactionAt = $this->date($record['transaction_at'] ?? null, 'transaction_at', $idempotencyKey);
        $postingAt = isset($record['posting_at']) && $record['posting_at'] !== ''
            ? $this->date($record['posting_at'], 'posting_at', $idempotencyKey)
            : null;
        if ($idempotencyKey === null) {
            throw new TransactionProcessingException(
                'normalize',
                'missing_idempotency_key',
                'An idempotency key or external bank transaction ID is required.',
            );
        }

        return new NormalizedTransaction(
            amountMinorUnits: $amount,
            currency: $currency,
            currencyExponent: $currencyExponent,
            type: $type,
            status: $status,
            transactionAt: $transactionAt,
            postingAt: $postingAt,
            description: $this->optionalString($record, 'description'),
            merchant: $this->optionalString($record, 'merchant'),
            externalBankTransactionId: $externalId,
            idempotencyKey: $idempotencyKey,
            categoryId: $this->optionalString($record, 'category_id'),
            originalTransactionId: $this->optionalString($record, 'original_transaction_id'),
            transferCounterpartAccountId: $this->optionalString($record, 'transfer_counterpart_account_id'),
            transferReference: $this->optionalVerbatimString($record, 'transfer_reference'),
            transferDirection: $direction,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function requiredString(array $record, string $key, ?string $idempotencyKey): string
    {
        $value = $record[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new TransactionProcessingException('normalize', 'invalid_'.$key, "{$key} must be a non-empty string.", $idempotencyKey);
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function optionalString(array $record, string $key): ?string
    {
        $value = $record[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new TransactionProcessingException('normalize', 'invalid_'.$key, "{$key} must be a string or null.");
        }

        return trim($value) === '' ? null : trim($value);
    }

    /**
     * Provider identifiers are preserved exactly as supplied for deduplication and traceability.
     *
     * @param  array<string, mixed>  $record
     */
    private function optionalVerbatimString(array $record, string $key): ?string
    {
        $value = $record[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new TransactionProcessingException('normalize', 'invalid_'.$key, "{$key} must be a string or null.");
        }

        return trim($value) === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function integer(array $record, string $key, ?string $idempotencyKey): int
    {
        $value = $record[$key] ?? null;

        if (! is_int($value) && (! is_string($value) || preg_match('/\A-?(?:0|[1-9]\d*)\z/D', $value) !== 1)) {
            throw new TransactionProcessingException('normalize', 'invalid_'.$key, "{$key} must be an integer.", $idempotencyKey);
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        if ($parsed === false) {
            throw new TransactionProcessingException('normalize', 'out_of_range_'.$key, "{$key} exceeds the supported integer range.", $idempotencyKey);
        }

        return $parsed;
    }

    /**
     * @param  class-string<TransactionType|TransactionStatus|TransferDirection>  $enum
     * @param  array<string, mixed>  $record
     */
    private function enumValue(string $enum, array $record, string $key, ?string $idempotencyKey): TransactionType|TransactionStatus|TransferDirection
    {
        $value = $this->requiredString($record, $key, $idempotencyKey);
        $normalized = $enum::tryFrom(strtoupper($value));

        if ($normalized === null) {
            throw new TransactionProcessingException('normalize', 'unknown_'.$key, "Unknown {$key} value.", $idempotencyKey);
        }

        return $normalized;
    }

    /**
     * @param  class-string<TransferDirection>  $enum
     * @param  array<string, mixed>  $record
     */
    private function optionalEnumValue(string $enum, array $record, string $key, ?string $idempotencyKey): ?TransferDirection
    {
        $value = $this->optionalString($record, $key);

        if ($value === null) {
            return null;
        }

        $normalized = $enum::tryFrom(strtoupper($value));

        if ($normalized === null) {
            throw new TransactionProcessingException('normalize', 'unknown_'.$key, "Unknown {$key} value.", $idempotencyKey);
        }

        return $normalized;
    }

    private function date(mixed $value, string $key, ?string $idempotencyKey): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        if (! is_string($value) || preg_match(
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D',
            $value,
        ) !== 1) {
            throw new TransactionProcessingException(
                'normalize',
                'invalid_'.$key,
                "{$key} must be an ISO-8601 timestamp with an explicit timezone.",
                $idempotencyKey,
            );
        }

        try {
            $date = new DateTimeImmutable($value);

            if ($date->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
                throw new \InvalidArgumentException('Timestamp components are not valid.');
            }

            return CarbonImmutable::instance($date)->utc();
        } catch (\Exception $exception) {
            throw new TransactionProcessingException('normalize', 'invalid_'.$key, "Invalid {$key} value.", $idempotencyKey);
        }
    }
}
