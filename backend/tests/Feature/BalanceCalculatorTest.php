<?php

namespace Tests\Feature;

use App\Domain\Accounts\BalanceCalculator;
use App\Domain\Transactions\TransactionStatus;
use App\Domain\Transactions\TransactionType;
use App\Domain\Transactions\TransferDirection;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BalanceCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_posted_income_expenses_fees_refunds_transfers_and_adjustments_are_calculated_as_integer_drams(): void
    {
        $owner = User::factory()->create();
        $source = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 100_000]);
        $destination = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 5_000]);
        $expense = $this->transaction($source, TransactionType::Expense, 5_000);
        $this->transaction($source, TransactionType::Income, 20_000);
        $this->transaction($source, TransactionType::Fee, 300);
        $this->transaction($source, TransactionType::Refund, 1_000, TransactionStatus::Posted, null, $expense->getKey());
        $this->transaction($source, TransactionType::Adjustment, -200);
        $this->transaction($source, TransactionType::Transfer, 1_000, TransactionStatus::Posted, TransferDirection::Out);
        $this->transaction($destination, TransactionType::Transfer, 1_000, TransactionStatus::Posted, TransferDirection::In);

        $balanceSheet = app(BalanceCalculator::class)->calculateForOwner($owner->id, 'AMD', 0);
        $accountBalances = collect($balanceSheet->accounts)->keyBy('accountId');

        $this->assertSame(120_500, $balanceSheet->ownedFunds->minorUnits);
        $this->assertSame(0, $balanceSheet->creditLiabilities->minorUnits);
        $this->assertSame(114_500, $accountBalances[$source->getKey()]->postedBalance->minorUnits);
    }

    public function test_pending_transactions_are_separate_and_reversed_or_declined_transactions_are_excluded(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 50_000]);
        $this->transaction($account, TransactionType::Expense, 2_000, TransactionStatus::Pending);
        $this->transaction($account, TransactionType::Expense, 80_000, TransactionStatus::Reversed);
        $this->transaction($account, TransactionType::Expense, 90_000, TransactionStatus::Declined);

        $balanceSheet = app(BalanceCalculator::class)->calculateForOwner($owner->id, 'AMD', 0);

        $this->assertSame(50_000, $balanceSheet->ownedFunds->minorUnits);
        $this->assertSame(-2_000, $balanceSheet->pendingOwnedFundsChange->minorUnits);
    }

    public function test_credit_liabilities_are_separate_from_owned_funds_and_credit_limits(): void
    {
        $owner = User::factory()->create();
        $asset = Account::factory()->for($owner, 'owner')->create([
            'opening_balance_minor_units' => 50_000,
        ]);
        $credit = Account::factory()->credit()->for($owner, 'owner')->create([
            'opening_balance_minor_units' => 5_000,
            'credit_limit_minor_units' => 2_000_000,
        ]);
        $expense = $this->transaction($credit, TransactionType::Expense, 2_000);
        $this->transaction($credit, TransactionType::Refund, 300, TransactionStatus::Posted, null, $expense->getKey());
        $this->transaction($credit, TransactionType::Expense, 500, TransactionStatus::Pending);
        $this->transaction($asset, TransactionType::Income, 1_000);

        $balanceSheet = app(BalanceCalculator::class)->calculateForOwner($owner->id, 'AMD', 0);

        $this->assertSame(51_000, $balanceSheet->ownedFunds->minorUnits);
        $this->assertSame(6_700, $balanceSheet->creditLiabilities->minorUnits);
        $this->assertSame(500, $balanceSheet->pendingCreditLiabilityChange->minorUnits);
    }

    private function transaction(
        Account $account,
        TransactionType $type,
        int $amount,
        TransactionStatus $status = TransactionStatus::Posted,
        ?TransferDirection $direction = null,
        ?string $originalTransactionId = null,
    ): Transaction {
        static $sequence = 0;
        $sequence++;

        return Transaction::factory()->for($account)->create([
            'amount_minor_units' => $amount,
            'type' => $type,
            'status' => $status,
            'transfer_direction' => $direction,
            'original_transaction_id' => $originalTransactionId,
            'idempotency_key' => 'balance-test-'.$sequence,
        ]);
    }
}
