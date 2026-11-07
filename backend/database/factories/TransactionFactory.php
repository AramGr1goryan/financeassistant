<?php

namespace Database\Factories;

use App\Domain\Transactions\TransactionStatus;
use App\Domain\Transactions\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'amount_minor_units' => 1_000,
            'currency' => 'AMD',
            'currency_exponent' => 0,
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Posted,
            'transaction_at' => now()->utc(),
            'posting_at' => now()->utc(),
            'description' => fake()->sentence(),
            'merchant' => fake()->company(),
            'external_bank_transaction_id' => null,
            'idempotency_key' => (string) Str::uuid(),
            'category_id' => null,
            'original_transaction_id' => null,
            'transfer_group_id' => null,
            'transfer_direction' => null,
            'metadata' => null,
        ];
    }
}
