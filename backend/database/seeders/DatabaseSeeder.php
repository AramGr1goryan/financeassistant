<?php

namespace Database\Seeders;

use App\Domain\Accounts\AccountType;
use App\Domain\Accounts\BudgetPlanningService;
use App\Domain\Income\IncomeExpectationService;
use App\Domain\Money\Money;
use App\Domain\Telegram\TelegramOwnerLinkService;
use App\Domain\Transactions\TransactionStatus;
use App\Domain\Transactions\TransactionType;
use App\Models\Account;
use App\Models\BudgetSetting;
use App\Models\IncomeExpectation;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->seedDefaultCategories();

        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $this->seedLocalDevelopmentFixtures();
    }

    private function seedDefaultCategories(): void
    {
        $roots = [
            'income' => [
                'name' => 'Income',
                'children' => [
                    'salary' => 'Salary',
                    'freelance' => 'Freelance & Consulting',
                ],
            ],
            'living-expenses' => [
                'name' => 'Living Expenses',
                'children' => [
                    'groceries' => 'Groceries',
                    'utilities' => 'Utilities & Internet',
                    'housing' => 'Housing & Rent',
                    'transport' => 'Transport',
                    'healthcare' => 'Healthcare & Pharmacy',
                ],
            ],
            'financial' => [
                'name' => 'Financial & Fees',
                'children' => [
                    'bank-fees' => 'Bank Fees',
                    'taxes' => 'Taxes & Duties',
                ],
            ],
        ];

        foreach ($roots as $rootSlug => $rootData) {
            $parent = TransactionCategory::query()->updateOrCreate(
                ['slug' => $rootSlug],
                [
                    'parent_id' => null,
                    'name' => $rootData['name'],
                    'is_active' => true,
                ],
            );

            foreach ($rootData['children'] as $childSlug => $childName) {
                TransactionCategory::query()->updateOrCreate(
                    ['slug' => $childSlug],
                    [
                        'parent_id' => $parent->getKey(),
                        'name' => $childName,
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    private function seedLocalDevelopmentFixtures(): void
    {
        $owner = User::query()->updateOrCreate(
            ['email' => 'owner@example.test'],
            [
                'name' => 'Dev Fixture Owner',
                'password' => Hash::make('password123'),
                'email_verified_at' => now('UTC'),
            ],
        );

        $assetAccount = Account::query()->updateOrCreate(
            [
                'owner_id' => $owner->getKey(),
                'name' => 'Primary Checking [DEV FIXTURE]',
            ],
            [
                'type' => AccountType::Asset,
                'currency' => 'AMD',
                'currency_exponent' => 0,
                'opening_balance_minor_units' => 150_000,
                'credit_limit_minor_units' => null,
                'is_active' => true,
            ],
        );

        $creditAccount = Account::query()->updateOrCreate(
            [
                'owner_id' => $owner->getKey(),
                'name' => 'Credit Card [DEV FIXTURE]',
            ],
            [
                'type' => AccountType::Credit,
                'currency' => 'AMD',
                'currency_exponent' => 0,
                'opening_balance_minor_units' => 12_000,
                'credit_limit_minor_units' => 500_000,
                'is_active' => true,
            ],
        );

        $utilitiesCategory = TransactionCategory::query()->where('slug', 'utilities')->first();
        $healthcareCategory = TransactionCategory::query()->where('slug', 'healthcare')->first();
        $freelanceCategory = TransactionCategory::query()->where('slug', 'freelance')->first();

        Transaction::query()->updateOrCreate(
            [
                'account_id' => $assetAccount->getKey(),
                'idempotency_key' => 'dev-fixture-pending-outflow-1',
            ],
            [
                'amount_minor_units' => 10_000,
                'currency' => 'AMD',
                'currency_exponent' => 0,
                'type' => TransactionType::Expense,
                'status' => TransactionStatus::Pending,
                'transaction_at' => '2026-11-07T08:00:00Z',
                'posting_at' => null,
                'description' => 'Utility hold [DEV FIXTURE]',
                'merchant' => 'Electric Co',
                'category_id' => $utilitiesCategory?->getKey(),
            ],
        );

        Transaction::query()->updateOrCreate(
            [
                'account_id' => $assetAccount->getKey(),
                'idempotency_key' => 'dev-fixture-pending-inflow-1',
            ],
            [
                'amount_minor_units' => 40_000,
                'currency' => 'AMD',
                'currency_exponent' => 0,
                'type' => TransactionType::Income,
                'status' => TransactionStatus::Pending,
                'transaction_at' => '2026-11-07T09:00:00Z',
                'posting_at' => null,
                'description' => 'Pending client transfer [DEV FIXTURE]',
                'merchant' => 'Client Corp',
                'category_id' => $freelanceCategory?->getKey(),
            ],
        );

        Transaction::query()->updateOrCreate(
            [
                'account_id' => $creditAccount->getKey(),
                'idempotency_key' => 'dev-fixture-credit-posted-1',
            ],
            [
                'amount_minor_units' => 3_000,
                'currency' => 'AMD',
                'currency_exponent' => 0,
                'type' => TransactionType::Expense,
                'status' => TransactionStatus::Posted,
                'transaction_at' => '2026-11-06T12:00:00Z',
                'posting_at' => '2026-11-06T14:00:00Z',
                'description' => 'Pharmacy [DEV FIXTURE]',
                'merchant' => 'Alfa Pharm',
                'category_id' => $healthcareCategory?->getKey(),
            ],
        );

        if (! BudgetSetting::query()->where('owner_id', $owner->getKey())->where('currency', 'AMD')->exists()) {
            app(BudgetPlanningService::class)->save(
                $owner,
                new Money(20_000, 'AMD', 0),
                new Money(30_000, 'AMD', 0),
                new Money(5_000, 'AMD', 0),
                30,
            );
        }

        if (! IncomeExpectation::query()->where('owner_id', $owner->getKey())->exists()) {
            app(IncomeExpectationService::class)->create(
                $owner,
                'Consulting retainer [DEV FIXTURE]',
                new Money(250_000, 'AMD', 0),
                '2026-11-15',
            );
        }

        $telegramService = app(TelegramOwnerLinkService::class);
        $configuredTelegramOwnerId = $telegramService->configuredOwnerTelegramId();
        if ($configuredTelegramOwnerId !== null) {
            $telegramService->linkVerifiedOwner($owner, $configuredTelegramOwnerId);
        }

        $secondOwner = User::query()->updateOrCreate(
            ['email' => 'second-owner@example.test'],
            [
                'name' => 'Second Dev Fixture Owner (No Budget)',
                'password' => Hash::make('password123'),
                'email_verified_at' => now('UTC'),
            ],
        );

        Account::query()->updateOrCreate(
            [
                'owner_id' => $secondOwner->getKey(),
                'name' => 'Secondary Checking [DEV FIXTURE]',
            ],
            [
                'type' => AccountType::Asset,
                'currency' => 'AMD',
                'currency_exponent' => 0,
                'opening_balance_minor_units' => 50_000,
                'credit_limit_minor_units' => null,
                'is_active' => true,
            ],
        );
    }
}
