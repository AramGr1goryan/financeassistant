<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresMigrationOrderingTest extends TestCase
{
    public function test_self_referential_foreign_keys_are_added_after_postgresql_primary_keys(): void
    {
        $previousConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql');

        try {
            foreach ([
                '2026_11_07_184817_create_transaction_categories_table.php',
                '2026_11_07_184818_create_transactions_table.php',
            ] as $filename) {
                $migration = require database_path('migrations/'.$filename);
                $queries = DB::connection('pgsql')->pretend(fn () => $migration->up());
                $statements = array_column($queries, 'query');
                $primary = null;
                $selfReference = null;
                $table = str_contains($filename, 'categories') ? 'transaction_categories' : 'transactions';

                foreach ($statements as $index => $statement) {
                    if (str_contains($statement, 'add primary key')) {
                        $primary = $index;
                    }

                    if (str_contains($statement, 'references "'.$table.'"')) {
                        $selfReference = $index;
                    }
                }

                $this->assertNotNull($primary, $filename);
                $this->assertNotNull($selfReference, $filename);
                $this->assertLessThan($selfReference, $primary, $filename);
            }
        } finally {
            DB::setDefaultConnection($previousConnection);
        }
    }
}
