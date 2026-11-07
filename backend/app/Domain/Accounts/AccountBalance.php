<?php

namespace App\Domain\Accounts;

use App\Domain\Money\Money;

final readonly class AccountBalance
{
    public function __construct(
        public string $accountId,
        public Money $postedBalance,
        public Money $pendingChange,
        public Money $pendingDebits,
        public Money $creditLiability,
        public Money $pendingCreditLiabilityChange,
    ) {}
}
