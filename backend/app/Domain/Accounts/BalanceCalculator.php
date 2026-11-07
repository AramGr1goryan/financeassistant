<?php

namespace App\Domain\Accounts;

use App\Domain\Money\Money;
use App\Domain\Transactions\TransactionStatus;
use App\Domain\Transactions\TransactionType;
use App\Domain\Transactions\TransferDirection;
use App\Models\Account;
use App\Models\Transaction;
use InvalidArgumentException;

final class BalanceCalculator
{
    public function calculateForOwner(int|string $ownerId, string $currency, mixed $currencyExponent): BalanceSheet
    {
        if (! is_int($currencyExponent)) {
            throw new InvalidArgumentException('Currency exponent must be an integer.');
        }

        $zero = Money::zero($currency, $currencyExponent);
        $ownedFunds = $zero;
        $pendingOwnedFundsChange = $zero;
        $pendingOwnedFundsDebits = $zero;
        $creditLiabilities = $zero;
        $pendingCreditLiabilityChange = $zero;
        $accountBalances = [];

        $accounts = Account::query()
            ->where('owner_id', $ownerId)
            ->where('currency', $currency)
            ->with(['transactions' => fn ($query) => $query
                ->whereIn('status', [TransactionStatus::Posted->value, TransactionStatus::Pending->value])
                ->orderBy('id')])
            ->orderBy('id')
            ->get();

        foreach ($accounts as $account) {
            if ($account->currency_exponent !== $currencyExponent) {
                throw new CurrencyExponentMismatchException('Account currency exponent does not match the requested balance currency.');
            }

            $balance = $this->calculateForAccount($account);
            $accountBalances[] = $balance;
            $ownedFunds = $ownedFunds->add($balance->postedBalance);
            $pendingOwnedFundsChange = $pendingOwnedFundsChange->add($balance->pendingChange);
            $pendingOwnedFundsDebits = $pendingOwnedFundsDebits->add($balance->pendingDebits);
            $creditLiabilities = $creditLiabilities->add($balance->creditLiability);
            $pendingCreditLiabilityChange = $pendingCreditLiabilityChange->add($balance->pendingCreditLiabilityChange);
        }

        return new BalanceSheet(
            $ownedFunds,
            $pendingOwnedFundsChange,
            $pendingOwnedFundsDebits,
            $creditLiabilities,
            $pendingCreditLiabilityChange,
            $accountBalances,
        );
    }

    private function calculateForAccount(Account $account): AccountBalance
    {
        $zero = Money::zero($account->currency, $account->currency_exponent);
        $isCreditAccount = $account->type === AccountType::Credit;
        $postedBalance = $isCreditAccount
            ? $zero
            : new Money($account->opening_balance_minor_units, $account->currency, $account->currency_exponent);
        $pendingChange = $zero;
        $pendingDebits = $zero;
        $creditLiability = $isCreditAccount
            ? new Money($account->opening_balance_minor_units, $account->currency, $account->currency_exponent)
            : $zero;
        $pendingCreditLiabilityChange = $zero;

        foreach ($account->transactions as $transaction) {
            $effect = new Money(
                $this->balanceEffect($transaction, $isCreditAccount),
                $account->currency,
                $account->currency_exponent,
            );

            if ($transaction->status === TransactionStatus::Posted) {
                if ($isCreditAccount) {
                    $creditLiability = $creditLiability->add($effect);
                } else {
                    $postedBalance = $postedBalance->add($effect);
                }
            } elseif ($transaction->status === TransactionStatus::Pending) {
                if ($isCreditAccount) {
                    $pendingCreditLiabilityChange = $pendingCreditLiabilityChange->add($effect);
                } else {
                    $pendingChange = $pendingChange->add($effect);
                    if ($effect->minorUnits < 0) {
                        $pendingDebits = $pendingDebits->subtract($effect);
                    }
                }
            }
        }

        return new AccountBalance(
            $account->getKey(),
            $postedBalance,
            $pendingChange,
            $pendingDebits,
            $creditLiability,
            $pendingCreditLiabilityChange,
        );
    }

    private function balanceEffect(Transaction $transaction, bool $isCreditAccount): int
    {
        $amount = $transaction->amount_minor_units;

        return match ($transaction->type) {
            TransactionType::Income => $amount,
            TransactionType::Expense, TransactionType::Fee => $isCreditAccount ? $amount : -$amount,
            TransactionType::Refund => $isCreditAccount ? -$amount : $amount,
            TransactionType::Transfer => $this->transferEffect($transaction->transfer_direction, $amount, $isCreditAccount),
            TransactionType::Adjustment => $amount,
        };
    }

    private function transferEffect(?TransferDirection $direction, int $amount, bool $isCreditAccount): int
    {
        if ($direction === null) {
            throw new InvalidArgumentException('Transfer transactions must have a direction.');
        }

        return match ($direction) {
            TransferDirection::In => $isCreditAccount ? -$amount : $amount,
            TransferDirection::Out => $isCreditAccount ? $amount : -$amount,
        };
    }
}
