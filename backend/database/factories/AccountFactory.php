<?php

namespace Database\Factories;

use App\Domain\Accounts\AccountType;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'name' => fake()->company(),
            'type' => AccountType::Asset,
            'currency' => 'AMD',
            'currency_exponent' => 0,
            'opening_balance_minor_units' => 0,
            'credit_limit_minor_units' => null,
            'is_active' => true,
        ];
    }

    public function credit(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => AccountType::Credit,
            'credit_limit_minor_units' => 5_000_000,
        ]);
    }
}
