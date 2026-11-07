<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\TransactionCategory;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_idempotent_and_creates_labeled_dev_fixtures_in_testing_and_local(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThanOrEqual(10, TransactionCategory::query()->count());
        $this->assertDatabaseHas('users', ['email' => 'owner@example.test']);
        $this->assertDatabaseHas('users', ['email' => 'second-owner@example.test']);
        $this->assertDatabaseHas('accounts', ['name' => 'Primary Checking [DEV FIXTURE]']);
    }

    public function test_seeder_only_creates_categories_in_production_and_never_creates_fixture_users(): void
    {
        $this->app['env'] = 'production';

        try {
            app(DatabaseSeeder::class)->run();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertGreaterThanOrEqual(10, TransactionCategory::query()->count());
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Account::query()->count());
    }
}
