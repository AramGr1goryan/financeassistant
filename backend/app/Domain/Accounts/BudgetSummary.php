<?php

namespace App\Domain\Accounts;

use App\Domain\Money\Money;

final readonly class BudgetSummary
{
    public function __construct(
        public BalanceSheet $balance,
        public Money $mandatoryPayments,
        public Money $protectedReserve,
        public Money $allocatedSavings,
        public int $planningDays,
        public Money $availableFunds,
        public Money $dailyLimit,
    ) {}
}
