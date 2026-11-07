<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = DB::connection($this->getConnection());

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement(
            'ALTER TABLE accounts ADD CONSTRAINT accounts_currency_exponent_range_check CHECK (currency_exponent BETWEEN 0 AND 6)',
        );
        $connection->statement(
            "ALTER TABLE accounts ADD CONSTRAINT accounts_amd_whole_currency_check CHECK (currency <> 'AMD' OR currency_exponent = 0)",
        );
        $connection->statement(
            "ALTER TABLE accounts ADD CONSTRAINT accounts_opening_balance_range_check CHECK (opening_balance_minor_units <> '-9223372036854775808'::bigint)",
        );
        $connection->statement(
            'ALTER TABLE accounts ADD CONSTRAINT accounts_credit_limit_nonnegative_check CHECK (credit_limit_minor_units IS NULL OR credit_limit_minor_units >= 0)',
        );
        $connection->statement(
            'ALTER TABLE accounts ADD CONSTRAINT accounts_id_currency_exponent_unique UNIQUE (id, currency, currency_exponent)',
        );
        $connection->statement(
            'ALTER TABLE transactions ADD CONSTRAINT transactions_currency_exponent_range_check CHECK (currency_exponent BETWEEN 0 AND 6)',
        );
        $connection->statement(
            "ALTER TABLE transactions ADD CONSTRAINT transactions_amd_whole_currency_check CHECK (currency <> 'AMD' OR currency_exponent = 0)",
        );
        $connection->statement(
            "ALTER TABLE transactions ADD CONSTRAINT transactions_amount_policy_check CHECK (amount_minor_units <> '-9223372036854775808'::bigint AND ((type = 'ADJUSTMENT' AND amount_minor_units <> 0) OR (type <> 'ADJUSTMENT' AND amount_minor_units > 0)))",
        );
        $connection->statement(
            'ALTER TABLE transactions ADD CONSTRAINT transactions_account_currency_exponent_foreign FOREIGN KEY (account_id, currency, currency_exponent) REFERENCES accounts (id, currency, currency_exponent)',
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = DB::connection($this->getConnection());

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement(
            'ALTER TABLE transactions DROP CONSTRAINT transactions_account_currency_exponent_foreign',
        );
        $connection->statement(
            'ALTER TABLE accounts DROP CONSTRAINT accounts_id_currency_exponent_unique',
        );
        $connection->statement(
            'ALTER TABLE transactions DROP CONSTRAINT transactions_amount_policy_check',
        );
        $connection->statement(
            'ALTER TABLE transactions DROP CONSTRAINT transactions_amd_whole_currency_check',
        );
        $connection->statement(
            'ALTER TABLE transactions DROP CONSTRAINT transactions_currency_exponent_range_check',
        );
        $connection->statement(
            'ALTER TABLE accounts DROP CONSTRAINT accounts_credit_limit_nonnegative_check',
        );
        $connection->statement(
            'ALTER TABLE accounts DROP CONSTRAINT accounts_opening_balance_range_check',
        );
        $connection->statement(
            'ALTER TABLE accounts DROP CONSTRAINT accounts_amd_whole_currency_check',
        );
        $connection->statement(
            'ALTER TABLE accounts DROP CONSTRAINT accounts_currency_exponent_range_check',
        );
    }
};
