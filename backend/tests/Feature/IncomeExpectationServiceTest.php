<?php

namespace Tests\Feature;

use App\Domain\Accounts\BudgetPlanningService;
use App\Domain\FinancialCalendar;
use App\Domain\Income\IncomeExpectationService;
use App\Domain\Income\IncomeExpectationStatus;
use App\Domain\Money\Money;
use App\Domain\Transactions\TransactionStatus;
use App\Domain\Transactions\TransactionType;
use App\Models\Account;
use App\Models\IncomeExpectation;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class IncomeExpectationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_expectations_do_not_change_the_ledger_balance_or_budget(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 100_000]);
        Transaction::factory()->for($account)->create([
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Pending,
            'amount_minor_units' => 7_000,
        ]);
        $budgetService = app(BudgetPlanningService::class);
        $budgetService->save($owner, new Money(10_000, 'AMD', 0), new Money(20_000, 'AMD', 0), Money::zero('AMD', 0));
        $service = app(IncomeExpectationService::class);

        $expectation = $service->create($owner, '  Salary  ', new Money(300_000, 'AMD', 0), '2026-01-02');
        $budget = $budgetService->calculate($owner, 'AMD', 0);

        $this->assertSame('Salary', $expectation->description);
        $this->assertSame('2026-01-02', $expectation->fresh()->expected_date);
        $this->assertSame(IncomeExpectationStatus::Planned, $expectation->status);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(100_000, $budget->balance->ownedFunds->minorUnits);
        $this->assertSame(-7_000, $budget->balance->pendingOwnedFundsChange->minorUnits);
        $this->assertSame(7_000, $budget->balance->pendingOwnedFundsDebits->minorUnits);
        $this->assertSame(63_000, $budget->availableFunds->minorUnits);
        $this->assertSame(2_100, $budget->dailyLimit->minorUnits);

        $service->complete($owner, $expectation->id);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame(63_000, $budgetService->calculate($owner, 'AMD', 0)->availableFunds->minorUnits);
    }

    public function test_listing_and_completion_are_scoped_to_the_owner_and_completion_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = app(IncomeExpectationService::class);
        $later = $service->create($owner, 'Later', new Money(80_000, 'AMD', 0), '2026-01-03');
        $earlier = $service->create($owner, 'Earlier', new Money(70_000, 'AMD', 0), '2026-01-02');
        $foreign = $service->create($other, 'Other owner', new Money(50_000, 'AMD', 0), '2026-01-01');

        $this->assertSame([$earlier->id, $later->id], $service->forOwner($owner)->modelKeys());
        $this->assertSame(IncomeExpectationStatus::Completed, $service->complete($owner, $earlier->id)->status);
        $this->assertSame(IncomeExpectationStatus::Completed, $service->complete($owner, $earlier->id)->status);

        try {
            $service->complete($owner, $foreign->id);
            $this->fail('Another owner must not be able to complete the expectation.');
        } catch (ModelNotFoundException) {
            $this->assertSame(IncomeExpectationStatus::Planned, $foreign->fresh()->status);
        }
    }

    public function test_rejects_invalid_input_without_creating_records(): void
    {
        $owner = User::factory()->create();
        $service = app(IncomeExpectationService::class);
        $valid = new Money(100_000, 'AMD', 0);

        foreach ([
            ['', $valid, '2026-01-01'],
            [str_repeat('a', 256), $valid, '2026-01-01'],
            ['Income', Money::zero('AMD', 0), '2026-01-01'],
            ['Income', new Money(-1, 'AMD', 0), '2026-01-01'],
            ['Income', $valid, '2026-02-30'],
            ['Income', $valid, '2026-1-01'],
            ['Income', $valid, '0000-01-01'],
        ] as [$description, $amount, $date]) {
            try {
                $service->create($owner, $description, $amount, $date);
                $this->fail('Invalid expectation must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, IncomeExpectation::query()->count());
            }
        }

        try {
            $service->create($owner, 'Income', new Money(1, 'AMD', 1), '2026-01-01');
            $this->fail('Invalid AMD exponent must be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, IncomeExpectation::query()->count());
        }
    }

    public function test_expected_date_is_a_yerevan_financial_date_not_a_utc_timestamp(): void
    {
        $owner = User::factory()->create();
        $expectation = app(IncomeExpectationService::class)
            ->create($owner, 'Income', new Money(1_000, 'AMD', 0), '2026-01-02');
        $calendar = app(FinancialCalendar::class);
        $bounds = $calendar->utcBoundsForFinancialDay($expectation->fresh()->expected_date);

        $this->assertSame('2026-01-01 20:00:00', $bounds['start']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-02 20:00:00', $bounds['end']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-02', $calendar->financialDate('2026-01-01T20:05:00Z'));
    }
}
