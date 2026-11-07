<?php

namespace App\Domain\Accounts;

use App\Domain\Money\Money;
use InvalidArgumentException;

final class BudgetCalculator
{
    public const DEFAULT_PLANNING_DAYS = 30;

    public function calculate(
        BalanceSheet $balance,
        Money $mandatoryPayments,
        Money $protectedReserve,
        Money $allocatedSavings,
        int $planningDays = self::DEFAULT_PLANNING_DAYS,
    ): BudgetSummary {
        if ($planningDays < 1) {
            throw new InvalidArgumentException('Planning horizon must be at least one day.');
        }

        $zero = Money::zero($balance->ownedFunds->currency, $balance->ownedFunds->currencyExponent);
        $deductions = [$balance->pendingOwnedFundsDebits, $mandatoryPayments, $protectedReserve, $allocatedSavings];

        foreach ($deductions as $deduction) {
            if ($deduction->compareTo($zero) < 0) {
                throw new InvalidArgumentException('Budget deductions must not be negative.');
            }
        }

        $available = $balance->ownedFunds->compareTo($zero) > 0 ? $balance->ownedFunds : $zero;

        foreach ($deductions as $deduction) {
            $available = $available->compareTo($deduction) > 0
                ? $available->subtract($deduction)
                : $zero;
        }

        $dailyLimit = new Money(
            intdiv($available->minorUnits, $planningDays),
            $available->currency,
            $available->currencyExponent,
        );

        return new BudgetSummary(
            $balance,
            $mandatoryPayments,
            $protectedReserve,
            $allocatedSavings,
            $planningDays,
            $available,
            $dailyLimit,
        );
    }
}
