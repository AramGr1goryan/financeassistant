<?php

namespace Tests\Feature;

use App\Domain\Accounts\BalanceCalculator;
use App\Domain\Accounts\BudgetCalculator;
use App\Domain\Money\Money;
use App\Domain\Transactions\TransactionStatus;
use App\Domain\Transactions\TransactionType;
use App\Domain\Transactions\TransferDirection;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_budget_uses_ledger_funds_and_pending_debits_without_counting_pending_income_or_credit(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 100_000]);
        $credit = Account::factory()->credit()->for($owner, 'owner')->create([
            'opening_balance_minor_units' => 40_000,
            'credit_limit_minor_units' => 2_000_000,
        ]);
        Transaction::factory()->for($account)->create([
            'type' => TransactionType::Income,
            'status' => TransactionStatus::Posted,
            'amount_minor_units' => 10_000,
        ]);
        Transaction::factory()->for($account)->create([
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Pending,
            'amount_minor_units' => 7_000,
        ]);
        Transaction::factory()->for($account)->create([
            'type' => TransactionType::Income,
            'status' => TransactionStatus::Pending,
            'amount_minor_units' => 20_000,
        ]);
        Transaction::factory()->for($credit)->create([
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Pending,
            'amount_minor_units' => 5_000,
        ]);

        $balance = app(BalanceCalculator::class)->calculateForOwner($owner->id, 'AMD', 0);
        $budget = app(BudgetCalculator::class)->calculate(
            $balance,
            new Money(20_000, 'AMD', 0),
            new Money(30_000, 'AMD', 0),
            new Money(10_000, 'AMD', 0),
            7,
        );

        $this->assertSame(110_000, $balance->ownedFunds->minorUnits);
        $this->assertSame(13_000, $balance->pendingOwnedFundsChange->minorUnits);
        $this->assertSame(7_000, $balance->pendingOwnedFundsDebits->minorUnits);
        $this->assertSame(40_000, $balance->creditLiabilities->minorUnits);
        $this->assertSame(43_000, $budget->availableFunds->minorUnits);
        $this->assertSame(6_142, $budget->dailyLimit->minorUnits);
        $this->assertSame(7, $budget->planningDays);
    }

    public function test_deductions_exceeding_balance_never_produce_a_negative_daily_limit(): void
    {
        $owner = User::factory()->create();
        Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 5_000]);
        $balance = app(BalanceCalculator::class)->calculateForOwner($owner->id, 'AMD', 0);

        $budget = app(BudgetCalculator::class)->calculate(
            $balance,
            new Money(6_000, 'AMD', 0),
            new Money(PHP_INT_MAX, 'AMD', 0),
            new Money(PHP_INT_MAX, 'AMD', 0),
            1,
        );

        $this->assertSame(0, $budget->availableFunds->minorUnits);
        $this->assertSame(0, $budget->dailyLimit->minorUnits);
    }

    public function test_pending_internal_transfer_outflow_is_not_offset_by_its_pending_inflow(): void
    {
        $owner = User::factory()->create();
        $source = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 20_000]);
        $destination = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 5_000]);
        Transaction::factory()->for($source)->create([
            'type' => TransactionType::Transfer,
            'status' => TransactionStatus::Pending,
            'transfer_direction' => TransferDirection::Out,
            'amount_minor_units' => 3_000,
        ]);
        Transaction::factory()->for($destination)->create([
            'type' => TransactionType::Transfer,
            'status' => TransactionStatus::Pending,
            'transfer_direction' => TransferDirection::In,
            'amount_minor_units' => 3_000,
        ]);
        $balance = app(BalanceCalculator::class)->calculateForOwner($owner->id, 'AMD', 0);
        $zero = Money::zero('AMD', 0);

        $budget = app(BudgetCalculator::class)->calculate($balance, $zero, $zero, $zero, 2);

        $this->assertSame(25_000, $balance->ownedFunds->minorUnits);
        $this->assertSame(0, $balance->pendingOwnedFundsChange->minorUnits);
        $this->assertSame(3_000, $balance->pendingOwnedFundsDebits->minorUnits);
        $this->assertSame(22_000, $budget->availableFunds->minorUnits);
        $this->assertSame(11_000, $budget->dailyLimit->minorUnits);
    }

    public function test_negative_owned_funds_are_clamped_to_zero(): void
    {
        $owner = User::factory()->create();
        Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => -5_000]);
        $balance = app(BalanceCalculator::class)->calculateForOwner($owner->id, 'AMD', 0);
        $zero = Money::zero('AMD', 0);

        $budget = app(BudgetCalculator::class)->calculate($balance, $zero, $zero, $zero, 30);

        $this->assertSame(-5_000, $balance->ownedFunds->minorUnits);
        $this->assertSame(0, $budget->availableFunds->minorUnits);
        $this->assertSame(0, $budget->dailyLimit->minorUnits);
    }

    public function test_default_planning_horizon_is_thirty_days_and_daily_limit_rounds_down(): void
    {
        $owner = User::factory()->create();
        Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 100_001]);
        $balance = app(BalanceCalculator::class)->calculateForOwner($owner->id, 'AMD', 0);
        $zero = Money::zero('AMD', 0);

        $budget = app(BudgetCalculator::class)->calculate($balance, $zero, $zero, $zero);

        $this->assertSame(30, $budget->planningDays);
        $this->assertSame(100_001, $budget->availableFunds->minorUnits);
        $this->assertSame(3_333, $budget->dailyLimit->minorUnits);
    }
}
