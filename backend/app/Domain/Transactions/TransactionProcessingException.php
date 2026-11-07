<?php

namespace App\Domain\Transactions;

use RuntimeException;

final class TransactionProcessingException extends RuntimeException
{
    public function __construct(
        public readonly string $stage,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $idempotencyKey = null,
    ) {
        parent::__construct($message);
    }
}
