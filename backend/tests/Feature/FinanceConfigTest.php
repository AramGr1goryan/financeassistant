<?php

namespace Tests\Feature;

use Tests\TestCase;

class FinanceConfigTest extends TestCase
{
    public function test_finance_defaults_use_amd_and_yerevan_display_timezone_while_app_timezone_remains_utc(): void
    {
        $this->assertSame('AMD', config('finance.currency'));
        $this->assertSame('Asia/Yerevan', config('finance.display_timezone'));
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_postgres_connection_avoids_session_level_initialization_for_transaction_pooling(): void
    {
        $connection = config('database.connections.pgsql');

        $this->assertArrayNotHasKey('timezone', $connection);
        $this->assertArrayNotHasKey('search_path', $connection);
    }

    public function test_postgres_direct_connection_uses_the_unpooled_database_url(): void
    {
        $connection = config('database.connections.pgsql_direct');

        $this->assertSame('pgsql', $connection['driver']);
        $this->assertSame(env('DATABASE_URL'), $connection['url']);
        $this->assertArrayNotHasKey('timezone', $connection);
        $this->assertArrayNotHasKey('search_path', $connection);
    }
}
