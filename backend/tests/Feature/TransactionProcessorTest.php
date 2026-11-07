<?php

namespace Tests\Feature;

use App\Domain\FinancialCalendar;
use App\Domain\Transactions\TransactionProcessingException;
use App\Domain\Transactions\TransactionProcessor;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_reimport_updates_the_existing_transaction_and_recalculates_posted_balance(): void
    {
        $account = $this->account(['opening_balance_minor_units' => 10_000]);
        $record = $this->record([
            'amount_minor_units' => 2_500,
            'status' => 'PENDING',
        ]);
        $processor = app(TransactionProcessor::class);

        $firstImport = $processor->process($account, $record);
        $secondImport = $processor->process($account, array_replace($record, [
            'idempotency_key' => 'changed-import-key',
            'status' => 'POSTED',
            'posting_at' => '2026-03-11T12:00:00+04:00',
        ]));

        $this->assertTrue($firstImport->wasCreated);
        $this->assertFalse($secondImport->wasCreated);
        $this->assertSame(1, Transaction::query()->count());
        $this->assertSame('POSTED', $secondImport->transaction->status->value);
        $this->assertSame(7_500, $secondImport->balanceSheet->ownedFunds->minorUnits);
        $this->assertSame(0, $secondImport->balanceSheet->pendingOwnedFundsChange->minorUnits);
    }

    public function test_internal_transfer_creates_two_rows_and_reimport_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $source = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 50_000]);
        $destination = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 10_000]);
        $record = $this->record([
            'amount_minor_units' => 8_000,
            'type' => 'TRANSFER',
            'transfer_direction' => 'OUT',
            'external_bank_transaction_id' => 'bank-transfer-out-1',
            'transfer_reference' => 'provider-transfer-123',
            'transfer_counterpart_account_id' => $destination->getKey(),
        ]);
        $processor = app(TransactionProcessor::class);

        $firstImport = $processor->process($source, $record);
        $secondImport = $processor->process($source, $record);
        $destinationImport = $processor->process($destination, array_replace($record, [
            'external_bank_transaction_id' => 'bank-transfer-in-1',
            'transfer_direction' => 'IN',
            'transfer_counterpart_account_id' => $source->getKey(),
        ]));
        $destinationReimport = $processor->process($destination, array_replace($record, [
            'external_bank_transaction_id' => 'bank-transfer-in-1',
            'transfer_direction' => 'IN',
            'transfer_counterpart_account_id' => $source->getKey(),
        ]));
        $transfers = Transaction::query()->orderBy('account_id')->get();
        $accountBalances = collect($destinationReimport->balanceSheet->accounts)->keyBy('accountId');

        $this->assertTrue($firstImport->wasCreated);
        $this->assertFalse($secondImport->wasCreated);
        $this->assertFalse($destinationImport->wasCreated);
        $this->assertFalse($destinationReimport->wasCreated);
        $this->assertCount(2, $transfers);
        $this->assertSame(60_000, $destinationReimport->balanceSheet->ownedFunds->minorUnits);
        $this->assertSame(42_000, $accountBalances[$source->getKey()]->postedBalance->minorUnits);
        $this->assertSame(18_000, $accountBalances[$destination->getKey()]->postedBalance->minorUnits);
        $this->assertSame('OUT', $transfers->firstWhere('account_id', $source->getKey())->transfer_direction->value);
        $this->assertSame('IN', $transfers->firstWhere('account_id', $destination->getKey())->transfer_direction->value);
        $this->assertSame(
            'bank-transfer-in-1',
            $transfers->firstWhere('account_id', $destination->getKey())->external_bank_transaction_id,
        );
        $this->assertSame(
            'bank-transfer-out-1',
            $transfers->firstWhere('account_id', $source->getKey())->external_bank_transaction_id,
        );
    }

    public function test_posting_the_second_transfer_side_posts_both_account_records(): void
    {
        $owner = User::factory()->create();
        $source = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 10_000]);
        $destination = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 5_000]);
        $processor = app(TransactionProcessor::class);
        $outgoing = $this->record([
            'amount_minor_units' => 1_000,
            'status' => 'PENDING',
            'type' => 'TRANSFER',
            'transfer_direction' => 'OUT',
            'transfer_reference' => 'pending-transfer-1',
            'transfer_counterpart_account_id' => $destination->getKey(),
        ]);
        $processor->process($source, $outgoing);

        $posted = $processor->process($destination, array_replace($outgoing, [
            'status' => 'POSTED',
            'transfer_direction' => 'IN',
            'transfer_counterpart_account_id' => $source->getKey(),
        ]));
        $transfers = Transaction::query()->where('transfer_reference', 'pending-transfer-1')->get();

        $this->assertCount(2, $transfers);
        $this->assertSame(
            ['POSTED', 'POSTED'],
            $transfers->sortBy('account_id')->map(fn (Transaction $transaction) => $transaction->status->value)->values()->all(),
        );
        $this->assertSame(15_000, $posted->balanceSheet->ownedFunds->minorUnits);
        $this->assertSame(0, $posted->balanceSheet->pendingOwnedFundsChange->minorUnits);
    }

    public function test_conflicting_second_transfer_side_rolls_back_without_partially_updating_the_pair(): void
    {
        $owner = User::factory()->create();
        $source = Account::factory()->for($owner, 'owner')->create();
        $destination = Account::factory()->for($owner, 'owner')->create();
        $processor = app(TransactionProcessor::class);
        $outgoing = $this->record([
            'amount_minor_units' => 1_000,
            'type' => 'TRANSFER',
            'transfer_direction' => 'OUT',
            'transfer_reference' => 'conflicting-transfer-1',
            'transfer_counterpart_account_id' => $destination->getKey(),
        ]);
        $processor->process($source, $outgoing);

        try {
            $processor->process($destination, array_replace($outgoing, [
                'amount_minor_units' => 2_000,
                'external_bank_transaction_id' => 'conflicting-transfer-in',
                'transfer_direction' => 'IN',
                'transfer_counterpart_account_id' => $source->getKey(),
            ]));
            $this->fail('A conflicting transfer side must be rejected.');
        } catch (TransactionProcessingException $exception) {
            $this->assertSame('counterpart_transaction_conflict', $exception->errorCode);
        }

        $this->assertSame(2, Transaction::query()->count());
        $this->assertDatabaseHas('transactions', [
            'account_id' => $destination->getKey(),
            'amount_minor_units' => 1_000,
            'external_bank_transaction_id' => null,
            'is_generated_counterpart' => true,
        ]);
    }

    public function test_non_adjustment_transactions_reject_zero_and_negative_amounts(): void
    {
        $account = $this->account();
        $processor = app(TransactionProcessor::class);

        foreach ([0, -1] as $amount) {
            try {
                $processor->process($account, $this->record([
                    'amount_minor_units' => $amount,
                    'external_bank_transaction_id' => 'invalid-amount-'.$amount,
                ]));
                $this->fail('Non-adjustment transaction amounts must be positive.');
            } catch (TransactionProcessingException $exception) {
                $this->assertSame('non_positive_amount', $exception->errorCode);
            }
        }

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_signed_adjustments_are_allowed_but_zero_adjustments_are_rejected(): void
    {
        $account = $this->account();
        $processor = app(TransactionProcessor::class);
        $adjustment = $processor->process($account, $this->record([
            'amount_minor_units' => -250,
            'type' => 'ADJUSTMENT',
        ]));

        try {
            $processor->process($account, $this->record([
                'amount_minor_units' => 0,
                'external_bank_transaction_id' => 'zero-adjustment',
                'type' => 'ADJUSTMENT',
            ]));
            $this->fail('Zero adjustments must be rejected.');
        } catch (TransactionProcessingException $exception) {
            $this->assertSame('zero_adjustment', $exception->errorCode);
        }

        $this->assertSame(-250, $adjustment->balanceSheet->ownedFunds->minorUnits);
        $this->assertSame(1, Transaction::query()->count());
    }

    public function test_invalid_transactions_are_logged_and_never_persisted(): void
    {
        $account = $this->account();
        Log::spy();

        try {
            app(TransactionProcessor::class)->process($account, $this->record(['type' => 'PREDICTED']));
            $this->fail('Unknown transaction types must be rejected.');
        } catch (TransactionProcessingException $exception) {
            $this->assertSame('normalize', $exception->stage);
            $this->assertSame('unknown_type', $exception->errorCode);
        }

        $this->assertSame(0, Transaction::query()->count());
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_transaction_timestamps_are_stored_in_utc_and_yerevan_day_queries_span_utc_dates(): void
    {
        $account = $this->account();
        $processor = app(TransactionProcessor::class);
        $first = $processor->process($account, $this->record([
            'external_bank_transaction_id' => 'late-utc-previous-day',
            'transaction_at' => '2026-03-11T00:05:00+04:00',
        ]))->transaction;
        $second = $processor->process($account, $this->record([
            'external_bank_transaction_id' => 'late-utc-same-day',
            'transaction_at' => '2026-03-11T23:55:00+04:00',
        ]))->transaction;
        $outsideDay = $processor->process($account, $this->record([
            'external_bank_transaction_id' => 'previous-financial-day',
            'transaction_at' => '2026-03-10T23:55:00+04:00',
        ]))->transaction;
        $bounds = app(FinancialCalendar::class)->utcBoundsForFinancialDay('2026-03-11');
        $dailyTransactions = Transaction::query()
            ->where('transaction_at', '>=', $bounds['start'])
            ->where('transaction_at', '<', $bounds['end'])
            ->get();

        $this->assertSame('2026-03-10T20:05:00+00:00', $first->transaction_at->utc()->toIso8601String());
        $this->assertSame('2026-03-11T19:55:00+00:00', $second->transaction_at->utc()->toIso8601String());
        $this->assertSame('2026-03-10T19:55:00+00:00', $outsideDay->transaction_at->utc()->toIso8601String());
        $this->assertCount(2, $dailyTransactions);
        $this->assertContains($first->getKey(), $dailyTransactions->modelKeys());
        $this->assertContains($second->getKey(), $dailyTransactions->modelKeys());
        $this->assertNotContains($outsideDay->getKey(), $dailyTransactions->modelKeys());
    }

    public function test_transfer_counterpart_must_be_owned_by_the_same_user(): void
    {
        $account = $this->account();
        $otherOwnerAccount = Account::factory()->create();

        try {
            app(TransactionProcessor::class)->process($account, $this->record([
                'type' => 'TRANSFER',
                'transfer_direction' => 'OUT',
                'transfer_reference' => 'cross-owner-transfer',
                'transfer_counterpart_account_id' => $otherOwnerAccount->getKey(),
            ]));
            $this->fail('Transfers to another owner must be rejected.');
        } catch (TransactionProcessingException $exception) {
            $this->assertSame('unknown_transfer_counterpart', $exception->errorCode);
        }

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_transaction_currency_must_match_its_account_currency_and_exponent(): void
    {
        $account = $this->account();

        try {
            app(TransactionProcessor::class)->process($account, $this->record([
                'currency' => 'USD',
                'currency_exponent' => 2,
            ]));
            $this->fail('A transaction in another currency must be rejected.');
        } catch (TransactionProcessingException $exception) {
            $this->assertSame('account_currency_mismatch', $exception->errorCode);
        }

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_internal_transfer_requires_a_stable_shared_reference(): void
    {
        $account = $this->account();
        $counterpart = Account::factory()->for($account->owner, 'owner')->create();

        try {
            app(TransactionProcessor::class)->process($account, $this->record([
                'type' => 'TRANSFER',
                'transfer_direction' => 'OUT',
                'transfer_counterpart_account_id' => $counterpart->getKey(),
            ]));
            $this->fail('Internal transfers must have a stable reference.');
        } catch (TransactionProcessingException $exception) {
            $this->assertSame('missing_transfer_reference', $exception->errorCode);
        }

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_an_import_without_a_stable_deduplication_key_is_rejected(): void
    {
        $account = $this->account();

        try {
            app(TransactionProcessor::class)->process($account, $this->record([
                'external_bank_transaction_id' => null,
            ]));
            $this->fail('Imports require an idempotency key or provider transaction ID.');
        } catch (TransactionProcessingException $exception) {
            $this->assertSame('missing_idempotency_key', $exception->errorCode);
        }

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_invalid_calendar_dates_are_rejected_without_being_normalized(): void
    {
        $account = $this->account();

        try {
            app(TransactionProcessor::class)->process($account, $this->record([
                'transaction_at' => '2026-02-30T12:00:00+04:00',
            ]));
            $this->fail('Invalid calendar dates must be rejected.');
        } catch (TransactionProcessingException $exception) {
            $this->assertSame('invalid_transaction_at', $exception->errorCode);
        }

        $this->assertSame(0, Transaction::query()->count());
    }

    public function test_external_bank_transaction_ids_are_preserved_verbatim(): void
    {
        $account = $this->account();

        $result = app(TransactionProcessor::class)->process($account, $this->record([
            'external_bank_transaction_id' => ' bank-id-1 ',
        ]));

        $this->assertSame(' bank-id-1 ', $result->transaction->external_bank_transaction_id);
        $this->assertSame(' bank-id-1 ', $result->transaction->idempotency_key);
    }

    public function test_reimport_cannot_silently_change_a_stored_posted_amount(): void
    {
        $account = $this->account();
        $processor = app(TransactionProcessor::class);
        $record = $this->record(['amount_minor_units' => 1_000]);
        $transaction = $processor->process($account, $record)->transaction;

        try {
            $processor->process($account, array_replace($record, ['amount_minor_units' => 2_000]));
            $this->fail('A re-import must not silently overwrite a historical amount.');
        } catch (TransactionProcessingException $exception) {
            $this->assertSame('historical_transaction_conflict', $exception->errorCode);
        }

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->getKey(),
            'amount_minor_units' => 1_000,
        ]);
    }

    public function test_refund_can_link_to_its_original_expense(): void
    {
        $account = $this->account();
        $processor = app(TransactionProcessor::class);
        $expense = $processor->process($account, $this->record([
            'amount_minor_units' => 1_000,
            'external_bank_transaction_id' => 'original-expense',
        ]))->transaction;
        $refund = $processor->process($account, $this->record([
            'amount_minor_units' => 250,
            'type' => 'REFUND',
            'external_bank_transaction_id' => 'refund-1',
            'original_transaction_id' => $expense->getKey(),
        ]));

        $this->assertSame($expense->getKey(), $refund->transaction->original_transaction_id);
        $this->assertSame(-750, $refund->balanceSheet->ownedFunds->minorUnits);
    }

    public function test_cash_accounts_cannot_be_persisted(): void
    {
        $owner = User::factory()->create();
        $this->expectException(QueryException::class);

        DB::table('accounts')->insert([
            'id' => (string) Str::uuid(),
            'owner_id' => $owner->id,
            'name' => 'Cash',
            'type' => 'CASH',
            'currency' => 'AMD',
            'currency_exponent' => 0,
            'opening_balance_minor_units' => 0,
            'credit_limit_minor_units' => null,
            'is_active' => true,
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function record(array $overrides = []): array
    {
        return array_replace([
            'amount_minor_units' => 1_000,
            'currency' => 'AMD',
            'currency_exponent' => 0,
            'type' => 'EXPENSE',
            'status' => 'POSTED',
            'transaction_at' => '2026-03-11T12:00:00+04:00',
            'posting_at' => null,
            'description' => 'Groceries',
            'merchant' => 'Local Market',
            'external_bank_transaction_id' => 'bank-transaction-1',
            'category_id' => null,
            'original_transaction_id' => null,
            'transfer_counterpart_account_id' => null,
            'transfer_reference' => null,
            'transfer_direction' => null,
            'metadata' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function account(array $attributes = []): Account
    {
        $owner = User::factory()->create();

        return Account::factory()->for($owner, 'owner')->create($attributes);
    }
}
