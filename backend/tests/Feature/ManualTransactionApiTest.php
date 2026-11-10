<?php

namespace Tests\Feature;

use App\Domain\Transactions\TransactionStatus;
use App\Domain\Transactions\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualTransactionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_and_retry_manual_transaction_without_losing_integer_precision(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 0]);
        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'account_id' => $account->id,
            'amount_minor_units' => '9007199254740993',
            'type' => 'INCOME',
            'status' => 'POSTED',
            'transaction_date' => '2025-03-08',
            'description' => 'Salary',
        ];

        $this->actingAs($owner)->postJson('/api/v1/transactions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.amount.minor_units', '9007199254740993')
            ->assertJsonPath('data.transaction_date', '2025-03-08');
        $this->postJson('/api/v1/transactions', $payload)->assertOk()->assertJsonPath('was_created', false);
        $this->assertDatabaseCount('transactions', 1);
        $this->getJson('/api/v1/transactions')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.account_name', $account->name);
    }

    public function test_pending_manual_expense_does_not_change_posted_balance_and_is_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 10000]);
        $foreignAccount = Account::factory()->for($other, 'owner')->create();
        $this->actingAs($owner)->postJson('/api/v1/transactions', [
            'idempotency_key' => (string) Str::uuid(),
            'account_id' => $account->id,
            'amount_minor_units' => '1250',
            'type' => 'EXPENSE',
            'status' => 'PENDING',
            'transaction_date' => '2025-03-08',
        ])->assertCreated();
        $this->postJson('/api/v1/transactions', [
            'idempotency_key' => (string) Str::uuid(),
            'account_id' => $foreignAccount->id,
            'amount_minor_units' => '1',
            'type' => 'EXPENSE',
            'status' => 'POSTED',
            'transaction_date' => '2025-03-08',
        ])->assertNotFound();

        $this->getJson('/api/v1/balance?currency=AMD&currency_exponent=0')->assertOk()
            ->assertJsonPath('data.owned_funds.minor_units', '10000')
            ->assertJsonPath('data.pending_owned_funds_debits.minor_units', '1250');
        $this->actingAs($other)->getJson('/api/v1/transactions')->assertJsonCount(0, 'data');
    }

    public function test_internal_transfer_creates_a_matched_pair_atomically(): void
    {
        $owner = User::factory()->create();
        $source = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 10000]);
        $destination = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 0]);
        $this->actingAs($owner)->postJson('/api/v1/transactions', [
            'idempotency_key' => (string) Str::uuid(),
            'account_id' => $source->id,
            'amount_minor_units' => '3000',
            'type' => 'TRANSFER',
            'status' => 'PENDING',
            'transaction_date' => '2025-03-08',
            'transfer_direction' => 'OUT',
            'transfer_counterpart_account_id' => $destination->id,
        ])->assertCreated();

        $this->assertDatabaseCount('transactions', 2);
        $pair = Transaction::query()->orderBy('account_id')->get();
        $this->assertSame(TransactionType::Transfer, $pair[0]->type);
        $this->assertSame(TransactionStatus::Pending, $pair[1]->status);
        $this->assertSame($pair[0]->transfer_group_id, $pair[1]->transfer_group_id);
        $this->postJson('/api/v1/transactions/'.$pair[0]->id.'/status', ['status' => 'POSTED'])->assertOk();
        $this->assertSame(
            [TransactionStatus::Posted, TransactionStatus::Posted],
            Transaction::query()->orderBy('account_id')->get()->map(fn (Transaction $transaction) => $transaction->status)->all(),
        );
    }

    public function test_manual_transaction_rejects_invalid_amounts_and_foreign_transfer_counterparts(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = Account::factory()->for($owner, 'owner')->create();
        $foreign = Account::factory()->for($other, 'owner')->create();
        $this->actingAs($owner);

        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'account_id' => $account->id,
            'amount_minor_units' => '0',
            'type' => 'EXPENSE',
            'status' => 'POSTED',
            'transaction_date' => '2025-03-08',
        ];
        $this->postJson('/api/v1/transactions', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('amount_minor_units');

        $this->postJson('/api/v1/transactions', array_replace($payload, [
            'amount_minor_units' => '100',
            'type' => 'TRANSFER',
            'transfer_direction' => 'OUT',
            'transfer_counterpart_account_id' => $foreign->id,
        ]))->assertUnprocessable();
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_pending_transaction_can_be_posted_and_posted_transaction_can_be_reversed(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 5000]);
        $transactionId = $this->actingAs($owner)->postJson('/api/v1/transactions', [
            'idempotency_key' => (string) Str::uuid(),
            'account_id' => $account->id,
            'amount_minor_units' => '1000',
            'type' => 'EXPENSE',
            'status' => 'PENDING',
            'transaction_date' => '2025-03-08',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/transactions/{$transactionId}/status", ['status' => 'POSTED'])
            ->assertOk()->assertJsonPath('data.status', 'POSTED');
        $this->getJson('/api/v1/balance?currency=AMD&currency_exponent=0')->assertOk()
            ->assertJsonPath('data.owned_funds.minor_units', '4000')
            ->assertJsonPath('data.pending_owned_funds_debits.minor_units', '0');
        $this->postJson("/api/v1/transactions/{$transactionId}/status", ['status' => 'REVERSED'])
            ->assertOk()->assertJsonPath('data.status', 'REVERSED');
        $this->getJson('/api/v1/balance?currency=AMD&currency_exponent=0')->assertOk()
            ->assertJsonPath('data.owned_funds.minor_units', '5000');
        $this->postJson("/api/v1/transactions/{$transactionId}/status", ['status' => 'DECLINED'])->assertStatus(409);
    }
}
