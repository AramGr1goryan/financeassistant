<?php

namespace Tests\Feature;

use App\Domain\Accounts\BudgetPlanningService;
use App\Domain\Income\IncomeExpectationService;
use App\Domain\Money\Money;
use App\Models\Account;
use App\Models\BudgetSetting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostgresTransactionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $database = config('database.connections.pgsql.database');
        $designatedTestDatabase = getenv('PGSQL_TEST_DATABASE');
        $host = strtolower((string) config('database.connections.pgsql.host'));

        if (config('app.env') !== 'testing'
            || ! is_string($designatedTestDatabase)
            || $designatedTestDatabase === ''
            || $database !== $designatedTestDatabase
            || preg_match('/(?:^|[_-])test(?:ing)?\z/i', $designatedTestDatabase) !== 1
            || ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
            || filled(config('database.connections.pgsql.url'))) {
            throw new RuntimeException(
                'PostgreSQL integration tests require a local connection and an explicitly designated *_test or *_testing database.',
            );
        }
    }

    public function test_postgres_enforces_budget_setting_constraints(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL constraint integration tests require PostgreSQL.');
        }

        $owner = User::factory()->create();
        $zero = Money::zero('AMD', 0);
        $setting = app(BudgetPlanningService::class)->save($owner, $zero, $zero, $zero);

        foreach ([
            ['currency' => 'bad'],
            ['currency_exponent' => 1],
            ['currency_exponent' => 7],
            ['revision' => 0],
            ['planning_days' => 0],
            ['mandatory_payments_minor_units' => -1],
            ['protected_reserve_minor_units' => -1],
            ['allocated_savings_minor_units' => -1],
        ] as $invalid) {
            $this->assertPostgresRejects(fn () => DB::table('budget_settings')
                ->where('id', $setting->id)
                ->update($invalid));
        }

        $this->assertSame(1, BudgetSetting::query()->where('owner_id', $owner->id)->count());
    }

    public function test_postgres_enforces_income_expectation_constraints(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL constraint integration tests require PostgreSQL.');
        }

        $owner = User::factory()->create();
        $expectation = app(IncomeExpectationService::class)
            ->create($owner, 'Expected income', new Money(100_000, 'AMD', 0), '2026-01-02');

        foreach ([
            ['amount_minor_units' => 0],
            ['amount_minor_units' => -1],
            ['currency' => 'bad'],
            ['currency_exponent' => 1],
            ['currency_exponent' => 7],
            ['status' => 'POSTED'],
        ] as $invalid) {
            $this->assertPostgresRejects(fn () => DB::table('income_expectations')
                ->where('id', $expectation->id)
                ->update($invalid));
        }
    }

    public function test_postgres_enforces_financial_numeric_and_currency_constraints(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL constraint integration tests require PostgreSQL.');
        }

        $owner = User::factory()->create();
        $account = Account::factory()->for($owner, 'owner')->create();

        $this->assertPostgresRejects(fn () => DB::table('accounts')
            ->where('id', $account->getKey())
            ->update(['currency_exponent' => 7]));
        $this->assertPostgresRejects(fn () => DB::table('accounts')
            ->where('id', $account->getKey())
            ->update(['currency_exponent' => 1]));
        $this->assertPostgresRejects(fn () => DB::table('accounts')
            ->where('id', $account->getKey())
            ->update(['opening_balance_minor_units' => PHP_INT_MIN]));
        $this->assertPostgresRejects(fn () => DB::table('accounts')
            ->where('id', $account->getKey())
            ->update(['credit_limit_minor_units' => -1]));

        $transaction = Transaction::factory()->for($account)->create();

        foreach ([0, -1, PHP_INT_MIN] as $amount) {
            $this->assertPostgresRejects(fn () => DB::table('transactions')
                ->where('id', $transaction->getKey())
                ->update(['amount_minor_units' => $amount]));
        }

        $this->assertPostgresRejects(fn () => DB::table('transactions')
            ->where('id', $transaction->getKey())
            ->update(['currency' => 'USD']), '23503');
        $this->assertPostgresRejects(fn () => DB::table('transactions')
            ->where('id', $transaction->getKey())
            ->update(['currency_exponent' => 7]));
    }

    public function test_postgres_serializes_duplicate_and_paired_transfer_imports(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent import integration tests require PostgreSQL.');
        }

        $sessionTimezone = DB::selectOne('SHOW TIME ZONE');
        $this->assertSame('UTC', array_values((array) $sessionTimezone)[0]);

        $sameOutgoingPair = $this->accountPair();
        $sameOutgoing = $this->transferRecord(
            'same-outgoing',
            $sameOutgoingPair['destination']->getKey(),
            'outgoing-duplicate',
        );
        $duplicateOutgoingResults = $this->runConcurrently([
            ['account_id' => $sameOutgoingPair['source']->getKey(), 'record' => $sameOutgoing],
            ['account_id' => $sameOutgoingPair['source']->getKey(), 'record' => $sameOutgoing],
        ]);
        $sameOutgoingRows = $this->assertTransferPair('same-outgoing');

        $this->assertCount(1, array_filter($duplicateOutgoingResults, fn (array $result): bool => $result['wasCreated']));
        $this->assertSame(1, $sameOutgoingRows->whereNotNull('external_bank_transaction_id')->count());

        $sameIncomingPair = $this->accountPair();
        $sameIncoming = $this->transferRecord(
            'same-incoming',
            $sameIncomingPair['source']->getKey(),
            'incoming-duplicate',
            'IN',
        );
        $this->runConcurrently([
            ['account_id' => $sameIncomingPair['destination']->getKey(), 'record' => $sameIncoming],
            ['account_id' => $sameIncomingPair['destination']->getKey(), 'record' => $sameIncoming],
        ]);
        $sameIncomingRows = $this->assertTransferPair('same-incoming');

        $bothSidesPair = $this->accountPair();
        $bothSidesOutgoing = $this->transferRecord(
            'both-sides',
            $bothSidesPair['destination']->getKey(),
            'both-sides-out',
        );
        $bothSidesIncoming = $this->transferRecord(
            'both-sides',
            $bothSidesPair['source']->getKey(),
            'both-sides-in',
            'IN',
        );
        $this->runConcurrently([
            ['account_id' => $bothSidesPair['source']->getKey(), 'record' => $bothSidesOutgoing],
            ['account_id' => $bothSidesPair['destination']->getKey(), 'record' => $bothSidesIncoming],
        ]);
        $bothSidesRows = $this->assertTransferPair('both-sides');

        $differentTransfersPair = $this->accountPair();
        $this->runConcurrently([
            [
                'account_id' => $differentTransfersPair['source']->getKey(),
                'record' => $this->transferRecord(
                    'similar-transfer-a',
                    $differentTransfersPair['destination']->getKey(),
                    'similar-out-a',
                ),
            ],
            [
                'account_id' => $differentTransfersPair['source']->getKey(),
                'record' => $this->transferRecord(
                    'similar-transfer-b',
                    $differentTransfersPair['destination']->getKey(),
                    'similar-out-b',
                ),
            ],
        ]);
        $similarRowsA = $this->assertTransferPair('similar-transfer-a');
        $similarRowsB = $this->assertTransferPair('similar-transfer-b');

        $this->assertSame(1, $sameIncomingRows->whereNotNull('external_bank_transaction_id')->count());
        $this->assertSame(2, $bothSidesRows->where('is_generated_counterpart', false)->count());
        $this->assertNotSame(
            $similarRowsA->first()->transfer_group_id,
            $similarRowsB->first()->transfer_group_id,
        );
    }

    /**
     * @return array{source: Account, destination: Account}
     */
    private function accountPair(): array
    {
        $owner = User::factory()->create();

        return [
            'source' => Account::factory()->for($owner, 'owner')->create(),
            'destination' => Account::factory()->for($owner, 'owner')->create(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transferRecord(
        string $reference,
        string $counterpartId,
        string $externalId,
        string $direction = 'OUT',
    ): array {
        return [
            'amount_minor_units' => 25_000,
            'currency' => 'AMD',
            'currency_exponent' => 0,
            'type' => 'TRANSFER',
            'status' => 'POSTED',
            'transaction_at' => '2026-03-11T12:00:00+04:00',
            'posting_at' => null,
            'description' => 'Own-account transfer',
            'merchant' => null,
            'external_bank_transaction_id' => $externalId,
            'transfer_counterpart_account_id' => $counterpartId,
            'transfer_reference' => $reference,
            'transfer_direction' => $direction,
            'metadata' => null,
        ];
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function assertTransferPair(string $reference): Collection
    {
        $transactions = Transaction::query()->where('transfer_reference', $reference)->get();

        $this->assertCount(2, $transactions);
        $this->assertCount(1, $transactions->pluck('transfer_group_id')->unique());
        $this->assertCount(2, $transactions->pluck('account_id')->unique());
        $this->assertSame(['IN', 'OUT'], $transactions
            ->map(fn (Transaction $transaction): string => $transaction->transfer_direction->value)
            ->sort()
            ->values()
            ->all());

        return $transactions;
    }

    /**
     * @param  list<array{account_id: string, record: array<string, mixed>}>  $imports
     * @return list<array{wasCreated: bool}>
     */
    private function runConcurrently(array $imports): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'financeassistant-pg-'.Str::uuid();
        if (! mkdir($directory)) {
            $this->fail('Unable to create the PostgreSQL concurrency-test barrier.');
        }

        $releasePath = $directory.DIRECTORY_SEPARATOR.'release';
        $readyPaths = [];
        $processes = [];

        try {
            foreach ($imports as $index => $import) {
                $readyPath = $directory.DIRECTORY_SEPARATOR.'ready-'.$index;
                $readyPaths[] = $readyPath;
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/concurrent-transaction-import.php'),
                    $import['account_id'],
                    base64_encode(json_encode($import['record'], JSON_THROW_ON_ERROR)),
                    $readyPath,
                    $releasePath,
                ], base_path(), $this->postgresEnvironment(), null, 45);
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 20;
            do {
                $allReady = true;

                foreach ($processes as $index => $process) {
                    if (file_exists($readyPaths[$index])) {
                        continue;
                    }

                    if (! $process->isRunning()) {
                        $this->fail('A PostgreSQL import worker exited before reaching the barrier: '.$process->getErrorOutput());
                    }

                    $allReady = false;
                }

                if ($allReady) {
                    break;
                }

                if (microtime(true) >= $deadline) {
                    $this->fail('PostgreSQL import workers did not reach the concurrency barrier in time.');
                }

                usleep(10_000);
            } while (true);

            if (file_put_contents($releasePath, 'go', LOCK_EX) === false) {
                $this->fail('Unable to release PostgreSQL import workers.');
            }

            $results = [];

            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            foreach ([...$readyPaths, $releasePath] as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function postgresEnvironment(): array
    {
        $connection = config('database.connections.pgsql');

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_URL' => (string) ($connection['url'] ?? ''),
            'DB_HOST' => (string) ($connection['host'] ?? '127.0.0.1'),
            'DB_PORT' => (string) ($connection['port'] ?? '5432'),
            'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
            'DB_CHARSET' => (string) ($connection['charset'] ?? 'utf8'),
            'DB_SSLMODE' => (string) ($connection['sslmode'] ?? 'prefer'),
        ];
    }

    private function assertPostgresRejects(callable $operation, string $expectedSqlState = '23514'): void
    {
        try {
            $operation();
        } catch (QueryException $exception) {
            $this->assertSame($expectedSqlState, (string) $exception->getCode());

            return;
        }

        $this->fail('PostgreSQL must reject this invalid financial value.');
    }
}
