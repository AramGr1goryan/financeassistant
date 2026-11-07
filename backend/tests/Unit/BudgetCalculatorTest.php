<?php

namespace Tests\Unit;

use App\Domain\Accounts\BalanceSheet;
use App\Domain\Accounts\BudgetCalculator;
use App\Domain\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BudgetCalculatorTest extends TestCase
{
    public function test_zero_day_horizon_is_rejected(): void
    {
        $zero = Money::zero('AMD', 0);
        $this->expectException(InvalidArgumentException::class);

        (new BudgetCalculator)->calculate($this->balance(), $zero, $zero, $zero, 0);
    }

    public function test_negative_day_horizon_is_rejected(): void
    {
        $zero = Money::zero('AMD', 0);
        $this->expectException(InvalidArgumentException::class);

        (new BudgetCalculator)->calculate($this->balance(), $zero, $zero, $zero, -1);
    }

    public function test_negative_assumptions_are_rejected(): void
    {
        $zero = Money::zero('AMD', 0);
        $this->expectException(InvalidArgumentException::class);

        (new BudgetCalculator)->calculate($this->balance(), new Money(-1, 'AMD', 0), $zero, $zero, 1);
    }

    public function test_different_currencies_are_rejected_even_when_funds_are_already_zero(): void
    {
        $zero = Money::zero('AMD', 0);
        $this->expectException(InvalidArgumentException::class);

        (new BudgetCalculator)->calculate($this->balance(), $zero, $zero, Money::zero('USD', 2), 1);
    }

    private function balance(): BalanceSheet
    {
        $zero = Money::zero('AMD', 0);

        return new BalanceSheet($zero, $zero, $zero, $zero, $zero, []);
    }
}
