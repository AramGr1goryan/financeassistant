<?php

namespace App\Domain\Money;

use InvalidArgumentException;
use OverflowException;

final readonly class Money
{
    private const MAX_CURRENCY_EXPONENT = 6;

    public int $minorUnits;

    public string $currency;

    public int $currencyExponent;

    public function __construct(mixed $minorUnits, mixed $currency, mixed $currencyExponent)
    {
        if (! is_int($minorUnits) || ! is_string($currency) || ! is_int($currencyExponent)) {
            throw new InvalidArgumentException('Money requires integer minor units, a currency code, and an integer exponent.');
        }

        if ($minorUnits === PHP_INT_MIN) {
            throw new InvalidArgumentException('The minimum native integer is not a supported amount.');
        }

        if (preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new InvalidArgumentException('Currency must be an uppercase three-letter ISO code.');
        }

        if ($currencyExponent < 0 || $currencyExponent > self::MAX_CURRENCY_EXPONENT) {
            throw new InvalidArgumentException('Currency exponent is outside the supported range.');
        }

        if ($currency === 'AMD' && $currencyExponent !== 0) {
            throw new InvalidArgumentException('AMD amounts must use whole drams.');
        }

        $this->minorUnits = $minorUnits;
        $this->currency = $currency;
        $this->currencyExponent = $currencyExponent;
    }

    public static function zero(mixed $currency, mixed $currencyExponent): self
    {
        return new self(0, $currency, $currencyExponent);
    }

    public static function fromDecimalString(mixed $amount, mixed $currency, mixed $currencyExponent): self
    {
        if (! is_string($amount) || ! is_string($currency) || ! is_int($currencyExponent)) {
            throw new InvalidArgumentException('Decimal amounts must be supplied as strings with an integer exponent.');
        }

        if ($currencyExponent < 0 || $currencyExponent > self::MAX_CURRENCY_EXPONENT) {
            throw new InvalidArgumentException('Currency exponent is outside the supported range.');
        }

        if (preg_match('/\A([+-]?)(\d+)(?:\.(\d+))?\z/D', $amount, $matches) !== 1) {
            throw new InvalidArgumentException('Amount must be a plain decimal string.');
        }

        $fraction = $matches[3] ?? '';

        if (strlen($fraction) > $currencyExponent) {
            throw new InvalidArgumentException('Amount has more fractional digits than the currency supports.');
        }

        $digits = ltrim($matches[2].str_pad($fraction, $currencyExponent, '0'), '0');
        $digits = $digits === '' ? '0' : $digits;
        $maximum = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new OverflowException('Amount exceeds the supported integer range.');
        }

        $minorUnits = (int) $digits;

        if ($matches[1] === '-') {
            $minorUnits = -$minorUnits;
        }

        return new self($minorUnits, $currency, $currencyExponent);
    }

    public function add(self $other): self
    {
        $this->assertCompatible($other);

        if (($other->minorUnits > 0 && $this->minorUnits > PHP_INT_MAX - $other->minorUnits)
            || ($other->minorUnits < 0 && $this->minorUnits < PHP_INT_MIN - $other->minorUnits)) {
            throw new OverflowException('Money addition exceeds the supported integer range.');
        }

        $result = $this->minorUnits + $other->minorUnits;

        if ($result === PHP_INT_MIN) {
            throw new OverflowException('Money addition exceeds the supported integer range.');
        }

        return new self($result, $this->currency, $this->currencyExponent);
    }

    public function subtract(self $other): self
    {
        $this->assertCompatible($other);

        return $this->add(new self(-$other->minorUnits, $other->currency, $other->currencyExponent));
    }

    public function compareTo(self $other): int
    {
        $this->assertCompatible($other);

        return $this->minorUnits <=> $other->minorUnits;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency
            && $this->currencyExponent === $other->currencyExponent
            && $this->minorUnits === $other->minorUnits;
    }

    public function format(): string
    {
        $factor = 1;

        for ($digit = 0; $digit < $this->currencyExponent; $digit++) {
            $factor *= 10;
        }

        $absoluteAmount = abs($this->minorUnits);
        $wholeUnits = intdiv($absoluteAmount, $factor);
        $formatted = number_format($wholeUnits, 0, '.', ',');

        if ($this->currencyExponent > 0) {
            $fraction = str_pad((string) ($absoluteAmount % $factor), $this->currencyExponent, '0', STR_PAD_LEFT);
            $formatted .= '.'.$fraction;
        }

        return $this->currency.' '.($this->minorUnits < 0 ? '-' : '').$formatted;
    }

    private function assertCompatible(self $other): void
    {
        if ($this->currency !== $other->currency || $this->currencyExponent !== $other->currencyExponent) {
            throw new InvalidArgumentException('Money values must use the same currency and exponent.');
        }
    }
}
