<?php

namespace Tests\Unit;

use App\Domain\Money\Money;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_amd_arithmetic_uses_integer_drams(): void
    {
        $startingAmount = new Money(25_000, 'AMD', 0);
        $income = new Money(8_500, 'AMD', 0);
        $expense = new Money(4_250, 'AMD', 0);

        $balance = $startingAmount->add($income)->subtract($expense);

        $this->assertSame(29_250, $balance->minorUnits);
        $this->assertSame('AMD 29,250', $balance->format());
        $this->assertSame(1, $income->compareTo($expense));
        $this->assertTrue($balance->equals(new Money(29_250, 'AMD', 0)));
    }

    public function test_decimal_strings_convert_without_binary_floating_point(): void
    {
        $amount = Money::fromDecimalString('1234.56', 'USD', 2);

        $this->assertSame(123_456, $amount->minorUnits);
        $this->assertSame('USD 1,234.56', $amount->format());
    }

    public function test_negative_amounts_format_and_compare_exactly(): void
    {
        $credit = new Money(-1_250, 'AMD', 0);

        $this->assertSame('AMD -1,250', $credit->format());
        $this->assertSame(-1, $credit->compareTo(Money::zero('AMD', 0)));
    }

    public function test_currency_or_exponent_mismatch_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Money(1_000, 'AMD', 0))->add(new Money(100, 'USD', 2));
    }

    public function test_decimal_precision_beyond_currency_exponent_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimalString('10.5', 'AMD', 0);
    }

    public function test_amd_cannot_use_a_fractional_currency_exponent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money(1, 'AMD', 2);
    }

    public function test_same_currency_with_different_exponents_cannot_be_compared(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Money(100, 'USD', 2))->compareTo(new Money(10, 'USD', 1));
    }

    public function test_arithmetic_overflow_is_rejected(): void
    {
        $this->expectException(OverflowException::class);

        (new Money(PHP_INT_MAX, 'AMD', 0))->add(new Money(1, 'AMD', 0));
    }

    public function test_unsupported_minimum_integer_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money(PHP_INT_MIN, 'AMD', 0);
    }

    public function test_float_minor_units_are_rejected_without_coercion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money(1.5, 'AMD', 0);
    }

    public function test_float_decimal_input_is_rejected_without_string_coercion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromDecimalString(1.5, 'USD', 2);
    }
}
