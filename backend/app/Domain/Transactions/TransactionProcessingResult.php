<?php

namespace App\Domain\Transactions;

use App\Domain\Accounts\BalanceSheet;
use App\Models\Transaction;

final readonly class TransactionProcessingResult
{
    public function __construct(
        public Transaction $transaction,
        public BalanceSheet $balanceSheet,
        public bool $wasCreated,
    ) {}
}
