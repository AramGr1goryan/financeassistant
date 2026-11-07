<?php

namespace App\Domain\Accounts;

use App\Domain\Money\Money;

final readonly class BalanceSheet
{
    /**
     * @param  list<AccountBalance>  $accounts
     */
    public function __construct(
        public Money $ownedFunds,
        public Money $pendingOwnedFundsChange,
        public Money $pendingOwnedFundsDebits,
        public Money $creditLiabilities,
        public Money $pendingCreditLiabilityChange,
        public array $accounts,
    ) {}
}
