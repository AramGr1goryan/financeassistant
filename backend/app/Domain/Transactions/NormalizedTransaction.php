<?php

namespace App\Domain\Transactions;

use Carbon\CarbonImmutable;

final readonly class NormalizedTransaction
{
    public function __construct(
        public int $amountMinorUnits,
        public string $currency,
        public int $currencyExponent,
        public TransactionType $type,
        public TransactionStatus $status,
        public CarbonImmutable $transactionAt,
        public ?CarbonImmutable $postingAt,
        public ?string $description,
        public ?string $merchant,
        public ?string $externalBankTransactionId,
        public string $idempotencyKey,
        public ?string $categoryId,
        public ?string $originalTransactionId,
        public ?string $transferCounterpartAccountId,
        public ?string $transferReference,
        public ?TransferDirection $transferDirection,
        public ?array $metadata,
    ) {}
}
