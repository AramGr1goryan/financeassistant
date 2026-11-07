<?php

namespace Tests\Feature;

use App\Domain\Accounts\BudgetPlanningService;
use App\Domain\Money\Money;
use App\Domain\Transactions\TransactionProcessor;
use App\Domain\Transactions\TransactionStatus;
use App\Domain\Transactions\TransactionType;
use App\Models\Account;
use App\Models\BudgetSetting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BudgetPlanningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_assumptions_recalculate_from_only_the_owners_ledger_and_keep_history(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 100_000]);
        Account::factory()->for($other, 'owner')->create(['opening_balance_minor_units' => 999_999]);
        Transaction::factory()->for($account)->create([
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Pending,
            'amount_minor_units' => 7_000,
        ]);
        Transaction::factory()->for($account)->create([
            'type' => TransactionType::Income,
            'status' => TransactionStatus::Pending,
            'amount_minor_units' => 50_000,
        ]);
        $service = app(BudgetPlanningService::class);
        $first = $service->save($owner, new Money(10_000, 'AMD', 0), new Money(20_000, 'AMD', 0), new Money(3_000, 'AMD', 0));
        $second = $service->save($owner, new Money(20_000, 'AMD', 0), new Money(25_000, 'AMD', 0), new Money(5_000, 'AMD', 0), 10);

        $budget = $service->calculate($owner, 'AMD', 0);

        $this->assertSame(1, $first->revision);
        $this->assertSame(30, $first->planning_days);
        $this->assertSame(2, $second->revision);
        $this->assertSame(2, BudgetSetting::query()->where('owner_id', $owner->id)->count());
        $this->assertDatabaseHas('budget_settings', [
            'id' => $first->id,
            'mandatory_payments_minor_units' => 10_000,
            'protected_reserve_minor_units' => 20_000,
        ]);
        $this->assertSame(100_000, $budget->balance->ownedFunds->minorUnits);
        $this->assertSame(7_000, $budget->balance->pendingOwnedFundsDebits->minorUnits);
        $this->assertSame(43_000, $budget->availableFunds->minorUnits);
        $this->assertSame(4_300, $budget->dailyLimit->minorUnits);
        $this->assertSame(10, $budget->planningDays);

        Transaction::factory()->for($account)->create([
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Posted,
            'amount_minor_units' => 3_000,
        ]);

        $this->assertSame(40_000, $service->calculate($owner, 'AMD', 0)->availableFunds->minorUnits);
    }

    public function test_other_users_settings_are_never_used_as_a_fallback(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $zero = Money::zero('AMD', 0);
        $service = app(BudgetPlanningService::class);
        $service->save($owner, $zero, $zero, $zero);

        $this->expectException(ModelNotFoundException::class);

        $service->calculate($other, 'AMD', 0);
    }

    public function test_revisions_are_independent_for_each_owner(): void
    {
        $firstOwner = User::factory()->create();
        $secondOwner = User::factory()->create();
        $zero = Money::zero('AMD', 0);
        $service = app(BudgetPlanningService::class);
        $service->save($firstOwner, $zero, $zero, $zero);
        $service->save($firstOwner, $zero, $zero, $zero);

        $secondOwnerSetting = $service->save($secondOwner, $zero, $zero, $zero);

        $this->assertSame(1, $secondOwnerSetting->revision);
        $this->assertSame($secondOwner->id, $secondOwnerSetting->owner_id);
        $this->assertSame(3, BudgetSetting::query()->count());
    }

    public function test_database_rejects_duplicate_owner_currency_revision(): void
    {
        $owner = User::factory()->create();
        $zero = Money::zero('AMD', 0);
        $setting = app(BudgetPlanningService::class)->save($owner, $zero, $zero, $zero);
        $duplicate = $setting->replicate();

        $this->expectException(QueryException::class);

        $duplicate->save();
    }

    public function test_incompatible_currencies_are_rejected_without_writing_a_revision(): void
    {
        $owner = User::factory()->create();
        $service = app(BudgetPlanningService::class);
        $zero = Money::zero('AMD', 0);

        try {
            $service->save($owner, $zero, Money::zero('USD', 2), $zero);
            $this->fail('Currency mismatch must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, BudgetSetting::query()->count());
        }
    }

    public function test_invalid_horizons_and_negative_amounts_leave_no_records(): void
    {
        $owner = User::factory()->create();
        $service = app(BudgetPlanningService::class);
        $zero = Money::zero('AMD', 0);

        foreach ([0, -1, 1.5, '30', 2_147_483_648] as $days) {
            try {
                $service->save($owner, $zero, $zero, $zero, $days);
                $this->fail('Invalid planning days must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, BudgetSetting::query()->count());
            }
        }

        try {
            $service->save($owner, new Money(-1, 'AMD', 0), $zero, $zero);
            $this->fail('Negative assumptions must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, BudgetSetting::query()->count());
        }
    }

    public function test_budget_tracks_import_lifecycle_without_counting_pending_income(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 100_000]);
        $service = app(BudgetPlanningService::class);
        $zero = Money::zero('AMD', 0);
        $service->save($owner, new Money(10_000, 'AMD', 0), $zero, $zero, 10);
        $processor = app(TransactionProcessor::class);
        $record = [
            'amount_minor_units' => 7_000,
            'currency' => 'AMD',
            'currency_exponent' => 0,
            'type' => 'EXPENSE',
            'status' => 'PENDING',
            'transaction_at' => '2026-03-11T12:00:00+04:00',
            'external_bank_transaction_id' => 'expense-1',
        ];
        $processor->process($account, $record);
        $processor->process($account, array_replace($record, [
            'type' => 'INCOME',
            'amount_minor_units' => 50_000,
            'external_bank_transaction_id' => 'income-1',
        ]));

        $pending = $service->calculate($owner, 'AMD', 0);
        $this->assertSame(100_000, $pending->balance->ownedFunds->minorUnits);
        $this->assertSame(43_000, $pending->balance->pendingOwnedFundsChange->minorUnits);
        $this->assertSame(83_000, $pending->availableFunds->minorUnits);
        $this->assertSame(8_300, $pending->dailyLimit->minorUnits);

        $processor->process($account, array_replace($record, ['status' => 'POSTED']));
        $posted = $service->calculate($owner, 'AMD', 0);
        $this->assertSame(93_000, $posted->balance->ownedFunds->minorUnits);
        $this->assertSame(0, $posted->balance->pendingOwnedFundsDebits->minorUnits);
        $this->assertSame(83_000, $posted->availableFunds->minorUnits);

        $processor->process($account, array_replace($record, ['status' => 'REVERSED']));
        $reversed = $service->calculate($owner, 'AMD', 0);
        $this->assertSame(100_000, $reversed->balance->ownedFunds->minorUnits);
        $this->assertSame(90_000, $reversed->availableFunds->minorUnits);
        $this->assertSame(2, Transaction::query()->count());
    }

    public function test_credit_repayment_reduces_owned_funds_and_liability_without_counting_credit_limit(): void
    {
        $owner = User::factory()->create();
        $asset = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 100_000]);
        $credit = Account::factory()->credit()->for($owner, 'owner')->create([
            'opening_balance_minor_units' => 40_000,
            'credit_limit_minor_units' => 2_000_000,
        ]);
        $service = app(BudgetPlanningService::class);
        $zero = Money::zero('AMD', 0);
        $service->save($owner, $zero, $zero, $zero, 10);

        app(TransactionProcessor::class)->process($asset, [
            'amount_minor_units' => 15_000,
            'currency' => 'AMD',
            'currency_exponent' => 0,
            'type' => 'TRANSFER',
            'status' => 'POSTED',
            'transaction_at' => '2026-03-11T12:00:00+04:00',
            'external_bank_transaction_id' => 'credit-payment-1',
            'transfer_direction' => 'OUT',
            'transfer_reference' => 'credit-payment-reference',
            'transfer_counterpart_account_id' => $credit->getKey(),
        ]);

        $budget = $service->calculate($owner, 'AMD', 0);

        $this->assertSame(85_000, $budget->balance->ownedFunds->minorUnits);
        $this->assertSame(25_000, $budget->balance->creditLiabilities->minorUnits);
        $this->assertSame(85_000, $budget->availableFunds->minorUnits);
        $this->assertSame(8_500, $budget->dailyLimit->minorUnits);
        $this->assertSame(2, Transaction::query()->count());
    }
}
