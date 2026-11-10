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

class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_or_create_accounts(): void
    {
        $this->getJson('/api/v1/accounts')->assertUnauthorized();
        $this->postJson('/api/v1/accounts', [])->assertUnauthorized();
    }

    public function test_owner_can_create_and_retry_an_account_without_duplication(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $data = ['id' => (string) Str::uuid(), 'name' => 'Bank account', 'type' => 'ASSET', 'opening_balance' => '9007199254740993'];
        $this->actingAs($user)->postJson('/api/v1/accounts', $data + ['owner_id' => $other->id])->assertCreated();
        $this->postJson('/api/v1/accounts', $data)->assertCreated();
        $this->assertDatabaseCount('accounts', 1);
        $this->getJson('/api/v1/accounts')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.posted_balance.minor_units', '9007199254740993');
        $this->actingAs($other)->getJson('/api/v1/accounts')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/accounts', $data)->assertStatus(409);
        $this->assertDatabaseCount('accounts', 1);
    }

    public function test_accounts_show_ledger_derived_asset_and_credit_balances(): void
    {
        $user = User::factory()->create();
        $asset = Account::factory()->for($user, 'owner')->create(['opening_balance_minor_units' => 100000]);
        $credit = Account::factory()->credit()->for($user, 'owner')->create(['opening_balance_minor_units' => 30000]);
        Transaction::factory()->for($asset)->create(['type' => TransactionType::Expense, 'status' => TransactionStatus::Posted, 'amount_minor_units' => 10000]);
        Transaction::factory()->for($credit)->create(['type' => TransactionType::Expense, 'status' => TransactionStatus::Pending, 'amount_minor_units' => 5000]);
        $items = $this->actingAs($user)->getJson('/api/v1/accounts')->assertOk()->json('data');
        $byId = collect($items)->keyBy('id');
        $this->assertSame('90000', $byId[$asset->id]['posted_balance']['minor_units']);
        $this->assertSame('30000', $byId[$credit->id]['credit_liability']['minor_units']);
        $this->assertSame('5000', $byId[$credit->id]['pending_change']['minor_units']);
    }

    public function test_invalid_accounts_and_conflicting_retries_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $data = ['id' => (string) Str::uuid(), 'name' => 'Test', 'type' => 'ASSET', 'opening_balance' => '0'];
        foreach (['-1', '1.5', '9223372036854775808', 1] as $invalid) {
            $this->postJson('/api/v1/accounts', array_replace($data, ['opening_balance' => $invalid]))
                ->assertUnprocessable()->assertJsonValidationErrors('opening_balance');
        }
        $this->postJson('/api/v1/accounts', array_replace($data, ['type' => 'CASH']))->assertUnprocessable();
        $this->postJson('/api/v1/accounts', $data)->assertCreated();
        $this->postJson('/api/v1/accounts', array_replace($data, ['opening_balance' => '1']))->assertStatus(409);
        $this->assertDatabaseCount('accounts', 1);
    }

    public function test_creation_requires_csrf_outside_testing_environment(): void
    {
        $this->actingAs(User::factory()->create());
        $data = ['id' => (string) Str::uuid(), 'name' => 'Test', 'type' => 'CREDIT', 'opening_balance' => '0'];
        $this->app['env'] = 'production';
        try {
            $this->postJson('/api/v1/accounts', $data)->assertStatus(419);
            $token = str_repeat('a', 40);
            $this->withSession(['_token' => $token])->withHeader('X-CSRF-TOKEN', $token)
                ->postJson('/api/v1/accounts', $data)->assertCreated();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_manual_credit_opening_amount_is_liability_not_owned_funds(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/v1/accounts', [
            'id' => (string) Str::uuid(),
            'name' => 'Credit card',
            'type' => 'CREDIT',
            'opening_balance' => '45000',
        ])->assertCreated();

        $this->getJson('/api/v1/accounts')->assertOk()
            ->assertJsonPath('data.0.posted_balance.minor_units', '0')
            ->assertJsonPath('data.0.credit_liability.minor_units', '45000');
        $this->getJson('/api/v1/balance?currency=AMD&currency_exponent=0')->assertOk()
            ->assertJsonPath('data.owned_funds.minor_units', '0')
            ->assertJsonPath('data.credit_liabilities.minor_units', '45000');
    }
}
