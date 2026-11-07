<?php

namespace Tests\Feature;

use App\Domain\Accounts\BudgetPlanningService;
use App\Domain\Money\Money;
use App\Domain\Transactions\TransactionStatus;
use App\Domain\Transactions\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FinancialApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_financial_data_even_without_an_accept_header(): void
    {
        $this->get('/api/v1/balance?currency=AMD&currency_exponent=0')->assertUnauthorized();
        $this->getJson('/api/v1/budget?currency=AMD&currency_exponent=0')->assertUnauthorized();
    }

    public function test_sign_in_and_sign_out_guard_financial_routes(): void
    {
        $user = User::factory()->create(['password' => Hash::make('test-password-123')]);
        Account::factory()->for($user, 'owner')->create(['opening_balance_minor_units' => 100_000]);

        $this->get('/api/v1/session/csrf')->assertNoContent()->assertCookie('XSRF-TOKEN');
        $this->postJson('/api/v1/session', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/session', [
            'email' => $user->email,
            'password' => 'test-password-123',
        ])->assertNoContent();
        $this->getJson('/api/v1/balance?currency=AMD&currency_exponent=0')
            ->assertOk()->assertJsonPath('data.owned_funds.minor_units', '100000');
        $this->deleteJson('/api/v1/session')->assertNoContent();
        $this->getJson('/api/v1/balance?currency=AMD&currency_exponent=0')->assertUnauthorized();
    }

    public function test_login_and_logout_require_csrf_tokens_outside_test_environment(): void
    {
        $user = User::factory()->create(['password' => Hash::make('test-password-123')]);
        $this->app['env'] = 'production';

        try {
            $this->postJson('/api/v1/session', [
                'email' => $user->email,
                'password' => 'test-password-123',
            ])->assertStatus(419);
            $this->actingAs($user)->deleteJson('/api/v1/session')->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_valid_csrf_token_allows_session_login_and_logout(): void
    {
        $user = User::factory()->create(['password' => Hash::make('test-password-123')]);
        $this->app['env'] = 'production';

        try {
            $token = str_repeat('a', 40);
            $this->withSession(['_token' => $token])
                ->withHeader('X-CSRF-TOKEN', $token)
                ->postJson('/api/v1/session', [
                    'email' => $user->email,
                    'password' => 'test-password-123',
                ])->assertNoContent();
            $this->withHeader('X-CSRF-TOKEN', session()->token())
                ->deleteJson('/api/v1/session')->assertNoContent();
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_login_is_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/session', [
                'email' => 'nobody@example.test',
                'password' => 'incorrect',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/session', [
            'email' => 'nobody@example.test',
            'password' => 'incorrect',
        ])->assertTooManyRequests();
    }

    public function test_financial_responses_use_only_the_authenticated_owners_ledger_and_settings(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $asset = Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 100_000]);
        Account::factory()->for($other, 'owner')->create(['opening_balance_minor_units' => 9_000_000]);
        Account::factory()->credit()->for($owner, 'owner')->create([
            'opening_balance_minor_units' => 40_000,
            'credit_limit_minor_units' => 2_000_000,
        ]);
        Transaction::factory()->for($asset)->create([
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Pending,
            'amount_minor_units' => 7_000,
        ]);
        $zero = Money::zero('AMD', 0);
        $service = app(BudgetPlanningService::class);
        $service->save($owner, new Money(10_000, 'AMD', 0), $zero, $zero, 10);
        $service->save($other, $zero, $zero, $zero);

        $balance = $this->actingAs($owner)->getJson('/api/v1/balance?currency=AMD&currency_exponent=0&owner_id='.$other->id);
        $balance->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['data' => [
            'owned_funds' => ['minor_units' => '100000', 'currency' => 'AMD', 'currency_exponent' => 0],
            'pending_owned_funds_change' => ['minor_units' => '-7000', 'currency' => 'AMD', 'currency_exponent' => 0],
            'pending_owned_funds_debits' => ['minor_units' => '7000', 'currency' => 'AMD', 'currency_exponent' => 0],
            'credit_liabilities' => ['minor_units' => '40000', 'currency' => 'AMD', 'currency_exponent' => 0],
            'pending_credit_liability_change' => ['minor_units' => '0', 'currency' => 'AMD', 'currency_exponent' => 0],
        ]]);
        $this->getJson('/api/v1/budget?currency=AMD&currency_exponent=0&owner_id='.$other->id)
            ->assertOk()
            ->assertJsonPath('data.available_funds.minor_units', '83000')
            ->assertJsonPath('data.daily_limit.minor_units', '8300')
            ->assertJsonPath('data.planning_days', 10);
    }

    public function test_large_integer_amounts_are_returned_losslessly_as_strings(): void
    {
        $owner = User::factory()->create();
        Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 9_007_199_254_740_993]);

        $this->actingAs($owner)->getJson('/api/v1/balance?currency=AMD&currency_exponent=0')
            ->assertOk()
            ->assertJsonPath('data.owned_funds.minor_units', '9007199254740993');
    }

    public function test_missing_budget_does_not_fall_back_to_another_owners_settings(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $zero = Money::zero('AMD', 0);
        app(BudgetPlanningService::class)->save($other, $zero, $zero, $zero);

        $this->actingAs($owner)->getJson('/api/v1/budget?currency=AMD&currency_exponent=0')->assertNotFound();
    }

    public function test_invalid_or_missing_currency_parameters_are_rejected(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->getJson('/api/v1/balance')->assertUnprocessable()
            ->assertJsonValidationErrors(['currency', 'currency_exponent']);
        $this->getJson('/api/v1/balance?currency=amd&currency_exponent=0')->assertUnprocessable()
            ->assertJsonValidationErrors('currency');
        $this->getJson('/api/v1/budget?currency=AMD&currency_exponent=1')->assertUnprocessable()
            ->assertJsonValidationErrors('currency_exponent');
        $this->getJson('/api/v1/balance?currency=USD&currency_exponent=7')->assertUnprocessable()
            ->assertJsonValidationErrors('currency_exponent');
    }

    public function test_incompatible_currency_exponent_returns_a_validation_error(): void
    {
        $owner = User::factory()->create();
        Account::factory()->for($owner, 'owner')->create(['currency' => 'USD', 'currency_exponent' => 2]);
        $zero = Money::zero('USD', 2);
        app(BudgetPlanningService::class)->save($owner, $zero, $zero, $zero);

        $this->actingAs($owner)->getJson('/api/v1/balance?currency=USD&currency_exponent=3')
            ->assertUnprocessable()->assertJsonValidationErrors('currency_exponent');
        $this->getJson('/api/v1/budget?currency=USD&currency_exponent=3')
            ->assertUnprocessable()->assertJsonValidationErrors('currency_exponent');
    }

    public function test_public_registration_is_not_available(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Unauthorized',
            'email' => 'unauthorized@example.test',
            'password' => 'test-password-123',
        ])->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => 'unauthorized@example.test']);
    }
}
