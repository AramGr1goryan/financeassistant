<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BudgetSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetSettingApiTest extends TestCase
{
    use RefreshDatabase;

    private function validSettings(): array
    {
        return [
            'mandatory_payments' => '10000',
            'protected_reserve' => '20000',
            'allocated_savings' => '5000',
            'planning_days' => 10,
        ];
    }

    public function test_guest_cannot_save_settings(): void
    {
        $this->postJson('/api/v1/budget/settings', $this->validSettings())->assertUnauthorized();
        $this->assertDatabaseCount('budget_settings', 0);
    }

    public function test_save_creates_owner_scoped_revisions_used_by_the_budget(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 100_000]);

        $this->actingAs($other)->postJson('/api/v1/budget/settings', $this->validSettings())->assertNoContent();
        $this->actingAs($owner)->postJson('/api/v1/budget/settings', $this->validSettings() + ['owner_id' => $other->id])
            ->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/v1/budget/settings', array_replace($this->validSettings(), ['protected_reserve' => '30000']))
            ->assertNoContent();

        $this->assertSame(2, BudgetSetting::query()->where('owner_id', $owner->id)->count());
        $this->assertSame(1, BudgetSetting::query()->where('owner_id', $other->id)->count());
        $this->getJson('/api/v1/budget?currency=AMD&currency_exponent=0')
            ->assertOk()->assertJsonPath('data.available_funds.minor_units', '55000')
            ->assertJsonPath('data.daily_limit.minor_units', '5500')
            ->assertJsonPath('data.protected_reserve.minor_units', '30000');
    }

    public function test_invalid_values_do_not_create_a_revision(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['-1', '1.5', '01', '9223372036854775808', 42] as $invalid) {
            $this->postJson('/api/v1/budget/settings', array_replace($this->validSettings(), ['mandatory_payments' => $invalid]))
                ->assertUnprocessable()->assertJsonValidationErrors('mandatory_payments');
        }
        foreach ([0, -1, 2147483648, '1.5'] as $invalid) {
            $this->postJson('/api/v1/budget/settings', array_replace($this->validSettings(), ['planning_days' => $invalid]))
                ->assertUnprocessable()->assertJsonValidationErrors('planning_days');
        }
        $this->postJson('/api/v1/budget/settings', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['mandatory_payments', 'protected_reserve', 'allocated_savings', 'planning_days']);
        $this->assertDatabaseCount('budget_settings', 0);
    }

    public function test_zero_and_maximum_integer_amounts_are_preserved(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/budget/settings', [
                'mandatory_payments' => (string) PHP_INT_MAX,
                'protected_reserve' => '0',
                'allocated_savings' => '0',
                'planning_days' => 1,
            ])->assertNoContent();

        $this->getJson('/api/v1/budget?currency=AMD&currency_exponent=0')
            ->assertOk()->assertJsonPath('data.mandatory_payments.minor_units', (string) PHP_INT_MAX)
            ->assertJsonPath('data.available_funds.minor_units', '0');
    }

    public function test_write_requires_csrf_outside_testing_environment(): void
    {
        $this->actingAs(User::factory()->create());
        $this->app['env'] = 'production';
        try {
            $this->postJson('/api/v1/budget/settings', $this->validSettings())->assertStatus(419);
            $token = str_repeat('a', 40);
            $this->withSession(['_token' => $token])->withHeader('X-CSRF-TOKEN', $token)
                ->postJson('/api/v1/budget/settings', $this->validSettings())->assertNoContent();
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
