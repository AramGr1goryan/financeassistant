<?php

namespace App\Domain\Accounts;

use App\Domain\Money\Money;
use App\Models\BudgetSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OverflowException;

final class BudgetPlanningService
{
    public function __construct(
        private readonly BalanceCalculator $balances,
        private readonly BudgetCalculator $budgets,
    ) {}

    public function save(
        User $owner,
        Money $mandatoryPayments,
        Money $protectedReserve,
        Money $allocatedSavings,
        mixed $planningDays = BudgetCalculator::DEFAULT_PLANNING_DAYS,
    ): BudgetSetting {
        if (! is_int($planningDays) || $planningDays < 1 || $planningDays > 2_147_483_647) {
            throw new InvalidArgumentException('Planning days must be an integer between 1 and 2147483647.');
        }

        $zero = Money::zero($mandatoryPayments->currency, $mandatoryPayments->currencyExponent);

        foreach ([$mandatoryPayments, $protectedReserve, $allocatedSavings] as $amount) {
            if ($amount->compareTo($zero) < 0) {
                throw new InvalidArgumentException('Budget deductions must not be negative.');
            }
        }

        return DB::transaction(function () use ($owner, $mandatoryPayments, $protectedReserve, $allocatedSavings, $planningDays): BudgetSetting {
            $lockedOwner = User::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
            $revision = BudgetSetting::query()
                ->where('owner_id', $lockedOwner->getKey())
                ->where('currency', $mandatoryPayments->currency)
                ->max('revision');

            if ($revision !== null && (int) $revision === PHP_INT_MAX) {
                throw new OverflowException('Budget settings revision exceeds the supported range.');
            }

            $setting = new BudgetSetting([
                'currency' => $mandatoryPayments->currency,
                'currency_exponent' => $mandatoryPayments->currencyExponent,
                'revision' => $revision === null ? 1 : (int) $revision + 1,
                'planning_days' => $planningDays,
                'mandatory_payments_minor_units' => $mandatoryPayments->minorUnits,
                'protected_reserve_minor_units' => $protectedReserve->minorUnits,
                'allocated_savings_minor_units' => $allocatedSavings->minorUnits,
            ]);
            $setting->owner()->associate($lockedOwner);
            $setting->save();

            return $setting;
        });
    }

    public function calculate(User $owner, string $currency, mixed $currencyExponent): BudgetSummary
    {
        Money::zero($currency, $currencyExponent);
        $setting = BudgetSetting::query()
            ->where('owner_id', $owner->getKey())
            ->where('currency', $currency)
            ->orderByDesc('revision')
            ->firstOrFail();

        if ($setting->currency_exponent !== $currencyExponent) {
            throw new CurrencyExponentMismatchException('Budget currency exponent does not match the requested currency.');
        }

        return $this->budgets->calculate(
            $this->balances->calculateForOwner($owner->getKey(), $currency, $currencyExponent),
            new Money($setting->mandatory_payments_minor_units, $currency, $currencyExponent),
            new Money($setting->protected_reserve_minor_units, $currency, $currencyExponent),
            new Money($setting->allocated_savings_minor_units, $currency, $currencyExponent),
            $setting->planning_days,
        );
    }
}
