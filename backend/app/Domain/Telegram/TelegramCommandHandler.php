<?php

namespace App\Domain\Telegram;

use App\Domain\Accounts\AccountType;
use App\Domain\Accounts\BalanceCalculator;
use App\Domain\Accounts\BudgetCalculator;
use App\Domain\Accounts\BudgetPlanningService;
use App\Domain\Income\IncomeExpectationService;
use App\Domain\Money\Money;
use App\Domain\Notifications\NotificationCategory;
use App\Domain\Notifications\NotificationService;
use App\Domain\Transactions\TransactionProcessingException;
use App\Domain\Transactions\TransactionProcessor;
use App\Domain\Transactions\TransactionStatus;
use App\Domain\Transactions\TransactionType;
use App\Domain\Transactions\TransferDirection;
use App\Models\Account;
use App\Models\BudgetSetting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OverflowException;
use ValueError;

final class TelegramCommandHandler
{
    public function __construct(
        private readonly BalanceCalculator $balances,
        private readonly BudgetPlanningService $budgets,
        private readonly IncomeExpectationService $incomeExpectations,
        private readonly NotificationService $notifications,
        private readonly TransactionProcessor $transactionProcessor,
    ) {}

    public function handle(User $owner, string $rawText): TelegramCommandResult
    {
        $trimmed = trim($rawText);

        if ($trimmed === '' || ! str_starts_with($trimmed, '/')) {
            return new TelegramCommandResult(null, $this->helpText());
        }

        $parts = preg_split('/\s+/u', $trimmed, 2) ?: [];
        $commandToken = strtolower((string) ($parts[0] ?? ''));
        $command = explode('@', $commandToken, 2)[0];
        $argumentString = trim((string) ($parts[1] ?? ''));

        return match ($command) {
            '/start', '/help' => new TelegramCommandResult($command, $this->helpText()),
            '/widget' => new TelegramCommandResult($command, 'Use the buttons to load your balance or budget.'),
            '/app' => new TelegramCommandResult($command, 'Open the finance app. Sign in with your workspace account.'),
            '/balance' => new TelegramCommandResult($command, $this->handleBalance($owner, $argumentString)),
            '/budget' => new TelegramCommandResult($command, $this->handleBudget($owner, $argumentString)),
            '/set_budget' => new TelegramCommandResult($command, $this->handleSetBudget($owner, $argumentString)),
            '/accounts' => new TelegramCommandResult($command, $this->handleAccounts($owner)),
            '/create_account' => new TelegramCommandResult($command, $this->handleCreateAccount($owner, $argumentString)),
            '/transactions', '/tx' => new TelegramCommandResult($command, $this->handleTransactions($owner, $argumentString)),
            '/expense' => new TelegramCommandResult($command, $this->handleExpense($owner, $argumentString)),
            '/income' => new TelegramCommandResult($command, $this->handleIncome($owner, $argumentString)),
            '/transfer' => new TelegramCommandResult($command, $this->handleTransfer($owner, $argumentString)),
            '/post_tx' => new TelegramCommandResult($command, $this->handlePostTransaction($owner, $argumentString)),
            '/reverse_tx' => new TelegramCommandResult($command, $this->handleReverseTransaction($owner, $argumentString)),
            '/expected_income' => new TelegramCommandResult($command, $this->handleExpectedIncomeList($owner)),
            '/expect_income' => new TelegramCommandResult($command, $this->handleCreateExpectedIncome($owner, $argumentString)),
            '/complete_expected_income' => new TelegramCommandResult($command, $this->handleCompleteExpectedIncome($owner, $argumentString)),
            '/notifications' => new TelegramCommandResult($command, $this->handleNotifications($owner, $argumentString)),
            default => new TelegramCommandResult($command, 'Unknown command. '.$this->helpText()),
        };
    }

    private function handleBalance(User $owner, string $argumentString): string
    {
        try {
            [$currency, $exponent] = $this->resolveCurrency($owner, $argumentString);
            $sheet = $this->balances->calculateForOwner($owner->getKey(), $currency, $exponent);
        } catch (InvalidArgumentException $exception) {
            return 'Invalid currency request: '.$exception->getMessage();
        }

        return implode("\n", [
            "Balance summary ({$currency}):",
            'Posted balance: '.$sheet->ownedFunds->format(),
            'Pending change: '.$sheet->pendingOwnedFundsChange->format(),
            'Pending outflows: '.$sheet->pendingOwnedFundsDebits->format(),
            'Credit liabilities: '.$sheet->creditLiabilities->format(),
            'Pending credit change: '.$sheet->pendingCreditLiabilityChange->format(),
        ]);
    }

    private function handleBudget(User $owner, string $argumentString): string
    {
        try {
            [$currency, $exponent] = $this->resolveCurrency($owner, $argumentString);
            $summary = $this->budgets->calculate($owner, $currency, $exponent);
        } catch (ModelNotFoundException) {
            $currency = $argumentString !== '' ? strtoupper(trim($argumentString)) : $this->defaultCurrency();

            return "No budget assumptions are saved for {$currency}.";
        } catch (InvalidArgumentException $exception) {
            return 'Invalid budget request: '.$exception->getMessage();
        }

        return $this->formatBudgetSummary($currency, $summary->planningDays, [
            'Available funds: '.$summary->availableFunds->format(),
            'Daily limit: '.$summary->dailyLimit->format(),
            'Posted balance: '.$summary->balance->ownedFunds->format(),
            'Pending outflows: '.$summary->balance->pendingOwnedFundsDebits->format(),
            'Mandatory payments: '.$summary->mandatoryPayments->format(),
            'Protected reserve: '.$summary->protectedReserve->format(),
            'Allocated savings: '.$summary->allocatedSavings->format(),
            'Credit liabilities: '.$summary->balance->creditLiabilities->format(),
        ]);
    }

    private function handleSetBudget(User $owner, string $argumentString): string
    {
        $tokens = $argumentString === '' ? [] : (preg_split('/\s+/u', $argumentString) ?: []);

        if (count($tokens) < 3 || count($tokens) > 4) {
            return 'Usage: /set_budget <mandatory> <reserve> <savings> [planning_days]';
        }

        try {
            [$currency, $exponent] = $this->resolveCurrency($owner, '');
            $mandatory = Money::fromDecimalString($tokens[0], $currency, $exponent);
            $reserve = Money::fromDecimalString($tokens[1], $currency, $exponent);
            $savings = Money::fromDecimalString($tokens[2], $currency, $exponent);
            $planningDays = BudgetCalculator::DEFAULT_PLANNING_DAYS;

            if (isset($tokens[3])) {
                if (preg_match('/\A[1-9]\d{0,9}\z/D', $tokens[3]) !== 1) {
                    return 'Planning days must be a positive integer.';
                }

                $planningDays = (int) $tokens[3];
            }

            $setting = $this->budgets->save($owner, $mandatory, $reserve, $savings, $planningDays);
            $summary = $this->budgets->calculate($owner, $currency, $exponent);
        } catch (InvalidArgumentException|OverflowException $exception) {
            return 'Unable to save budget assumptions: '.$exception->getMessage();
        }

        return $this->formatBudgetSummary($currency, $summary->planningDays, [
            'Revision: '.$setting->revision,
            'Available funds: '.$summary->availableFunds->format(),
            'Daily limit: '.$summary->dailyLimit->format(),
            'Mandatory payments: '.$summary->mandatoryPayments->format(),
            'Protected reserve: '.$summary->protectedReserve->format(),
            'Allocated savings: '.$summary->allocatedSavings->format(),
        ]);
    }

    private function handleAccounts(User $owner): string
    {
        try {
            $sheet = $this->balances->calculateForOwner($owner->getKey(), 'AMD', 0);
        } catch (\Throwable $e) {
            return 'Could not calculate accounts: '.$e->getMessage();
        }

        $accounts = Account::query()
            ->where('owner_id', $owner->getKey())
            ->where('currency', 'AMD')
            ->get()
            ->keyBy('id');

        if ($accounts->isEmpty()) {
            return "No active accounts found.\nUse /create_account <name> <depository|credit> [opening_balance] to create one.";
        }

        $lines = ['Accounts (AMD):'];

        foreach ($sheet->accounts as $balance) {
            $account = $accounts->get($balance->accountId);
            if ($account === null) {
                continue;
            }

            $type = $account->type->value;
            $status = $account->is_active ? '' : ' [INACTIVE]';

            if ($account->type === AccountType::Credit) {
                $lines[] = sprintf(
                    '- %s (%s)%s: Liability %s [Pending: %s]',
                    $account->name,
                    $type,
                    $status,
                    $balance->creditLiability->format(),
                    $balance->pendingCreditLiabilityChange->format(),
                );
            } else {
                $lines[] = sprintf(
                    '- %s (%s)%s: %s [Pending: %s]',
                    $account->name,
                    $type,
                    $status,
                    $balance->postedBalance->format(),
                    $balance->pendingChange->format(),
                );
            }
        }

        return implode("\n", $lines);
    }

    private function handleCreateAccount(User $owner, string $argumentString): string
    {
        $tokens = $this->parseArguments($argumentString, 3);

        if (count($tokens) < 2) {
            return "Usage: /create_account <name> <asset|credit> [opening_balance]\nExample: /create_account Checking asset 50000";
        }

        $name = trim($tokens[0]);
        $typeStr = strtolower(trim($tokens[1]));

        $type = match ($typeStr) {
            'asset', 'depository' => AccountType::Asset,
            'credit' => AccountType::Credit,
            default => null,
        };

        if ($type === null) {
            return "Invalid account type '{$tokens[1]}'. Must be 'asset' or 'credit'.";
        }

        $openingStr = isset($tokens[2]) ? trim($tokens[2]) : '0';

        try {
            $opening = Money::fromDecimalString($openingStr, 'AMD', 0);
        } catch (\Throwable $e) {
            return 'Invalid opening balance: '.$e->getMessage();
        }

        try {
            $account = DB::transaction(function () use ($owner, $name, $type, $opening): Account {
                $existing = Account::query()
                    ->where('owner_id', $owner->getKey())
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->first();

                if ($existing !== null) {
                    throw new InvalidArgumentException("An account named '{$name}' already exists.");
                }

                $account = new Account([
                    'name' => $name,
                    'type' => $type,
                    'currency' => 'AMD',
                    'currency_exponent' => 0,
                    'opening_balance_minor_units' => $opening->minorUnits,
                    'credit_limit_minor_units' => null,
                    'is_active' => true,
                ]);
                $account->id = (string) Str::uuid();
                $account->owner()->associate($owner);
                $account->save();

                return $account;
            });
        } catch (\Throwable $e) {
            return 'Could not create account: '.$e->getMessage();
        }

        return implode("\n", [
            'Account created successfully:',
            '- Name: '.$account->name,
            '- Type: '.$account->type->value,
            '- Opening balance: '.$opening->format(),
            '- ID: '.$account->id,
        ]);
    }

    private function handleTransactions(User $owner, string $argumentString): string
    {
        $limit = 10;
        if ($argumentString !== '') {
            if (preg_match('/\A[1-9]\d{0,2}\z/D', $argumentString) === 1) {
                $limit = min((int) $argumentString, 25);
            }
        }

        $transactions = Transaction::query()
            ->with('account:id,name')
            ->whereHas('account', fn ($q) => $q->where('owner_id', $owner->getKey()))
            ->orderByDesc('transaction_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($transactions->isEmpty()) {
            return 'No transactions recorded yet.';
        }

        $lines = ["Recent transactions (showing {$transactions->count()}):"];

        foreach ($transactions as $tx) {
            $accountName = $tx->account?->name ?? 'Unknown';
            $sign = match ($tx->type) {
                TransactionType::Expense, TransactionType::Fee => '-',
                TransactionType::Income, TransactionType::Refund => '+',
                TransactionType::Transfer => $tx->transfer_direction === TransferDirection::In ? '+' : '-',
                TransactionType::Adjustment => $tx->amount_minor_units >= 0 ? '+' : '',
            };
            $amount = new Money(abs($tx->amount_minor_units), $tx->currency, $tx->currency_exponent);
            $date = $tx->transaction_at->format('Y-m-d');
            $desc = $tx->description ? ' — '.$tx->description : '';

            $lines[] = sprintf(
                '- %s | %s%s%s | %s | %s [%s] (id: %s)',
                $date,
                $sign,
                $amount->format(),
                $desc,
                $accountName,
                $tx->type->value,
                $tx->status->value,
                substr((string) $tx->id, 0, 8),
            );
        }

        return implode("\n", $lines);
    }

    private function handleExpense(User $owner, string $argumentString): string
    {
        $tokens = $this->parseArguments($argumentString, 3);

        if (count($tokens) < 2) {
            return "Usage: /expense <account> <amount> [description]\nExample: /expense Checking 5000 Groceries";
        }

        $account = $this->resolveOwnerAccount($owner, $tokens[0]);
        if (! $account instanceof Account) {
            return $account;
        }

        try {
            $amount = Money::fromDecimalString($tokens[1], $account->currency, $account->currency_exponent);
            if ($amount->minorUnits <= 0) {
                return 'Amount must be a positive integer.';
            }
        } catch (\Throwable $e) {
            return 'Invalid amount: '.$e->getMessage();
        }

        $description = isset($tokens[2]) ? trim($tokens[2]) : null;
        $idempotencyKey = (string) Str::uuid();
        $now = Carbon::now('UTC');

        $record = [
            'idempotency_key' => $idempotencyKey,
            'amount_minor_units' => $amount->minorUnits,
            'currency' => $account->currency,
            'currency_exponent' => $account->currency_exponent,
            'type' => TransactionType::Expense->value,
            'status' => TransactionStatus::Posted->value,
            'transaction_at' => $now->toIso8601String(),
            'posting_at' => $now->toIso8601String(),
            'description' => $description,
        ];

        try {
            $result = $this->transactionProcessor->process($account, $record);
        } catch (TransactionProcessingException $e) {
            return 'Transaction rejected: '.$e->getMessage();
        } catch (\Throwable $e) {
            return 'Error recording transaction: '.$e->getMessage();
        }

        return implode("\n", [
            'Recorded expense:',
            '- Account: '.$account->name,
            '- Amount: '.$amount->format(),
            '- Description: '.($description ?? 'None'),
            '- Status: POSTED',
            '- Date: '.$now->format('Y-m-d'),
            '- ID: '.$result->transaction->id,
        ]);
    }

    private function handleIncome(User $owner, string $argumentString): string
    {
        $tokens = $this->parseArguments($argumentString, 3);

        if (count($tokens) < 2) {
            return "Usage: /income <account> <amount> [description]\nExample: /income Checking 100000 Salary";
        }

        $account = $this->resolveOwnerAccount($owner, $tokens[0]);
        if (! $account instanceof Account) {
            return $account;
        }

        try {
            $amount = Money::fromDecimalString($tokens[1], $account->currency, $account->currency_exponent);
            if ($amount->minorUnits <= 0) {
                return 'Amount must be a positive integer.';
            }
        } catch (\Throwable $e) {
            return 'Invalid amount: '.$e->getMessage();
        }

        $description = isset($tokens[2]) ? trim($tokens[2]) : null;
        $idempotencyKey = (string) Str::uuid();
        $now = Carbon::now('UTC');

        $record = [
            'idempotency_key' => $idempotencyKey,
            'amount_minor_units' => $amount->minorUnits,
            'currency' => $account->currency,
            'currency_exponent' => $account->currency_exponent,
            'type' => TransactionType::Income->value,
            'status' => TransactionStatus::Posted->value,
            'transaction_at' => $now->toIso8601String(),
            'posting_at' => $now->toIso8601String(),
            'description' => $description,
        ];

        try {
            $result = $this->transactionProcessor->process($account, $record);
        } catch (TransactionProcessingException $e) {
            return 'Transaction rejected: '.$e->getMessage();
        } catch (\Throwable $e) {
            return 'Error recording transaction: '.$e->getMessage();
        }

        return implode("\n", [
            'Recorded income:',
            '- Account: '.$account->name,
            '- Amount: '.$amount->format(),
            '- Description: '.($description ?? 'None'),
            '- Status: POSTED',
            '- Date: '.$now->format('Y-m-d'),
            '- ID: '.$result->transaction->id,
        ]);
    }

    private function handleTransfer(User $owner, string $argumentString): string
    {
        $tokens = $this->parseArguments($argumentString, 4);

        if (count($tokens) < 3) {
            return "Usage: /transfer <from_account> <to_account> <amount> [description]\nExample: /transfer Checking Savings 20000 Monthly savings";
        }

        $fromAccount = $this->resolveOwnerAccount($owner, $tokens[0]);
        if (! $fromAccount instanceof Account) {
            return "Source account: {$fromAccount}";
        }

        $toAccount = $this->resolveOwnerAccount($owner, $tokens[1]);
        if (! $toAccount instanceof Account) {
            return "Destination account: {$toAccount}";
        }

        if ($fromAccount->getKey() === $toAccount->getKey()) {
            return 'Source and destination accounts must be different.';
        }

        if ($fromAccount->currency !== $toAccount->currency) {
            return 'Transfers between different currencies are not supported.';
        }

        try {
            $amount = Money::fromDecimalString($tokens[2], $fromAccount->currency, $fromAccount->currency_exponent);
            if ($amount->minorUnits <= 0) {
                return 'Amount must be a positive integer.';
            }
        } catch (\Throwable $e) {
            return 'Invalid amount: '.$e->getMessage();
        }

        $description = isset($tokens[3]) ? trim($tokens[3]) : 'Internal transfer';
        $idempotencyKey = (string) Str::uuid();
        $now = Carbon::now('UTC');

        $record = [
            'idempotency_key' => $idempotencyKey,
            'amount_minor_units' => $amount->minorUnits,
            'currency' => $fromAccount->currency,
            'currency_exponent' => $fromAccount->currency_exponent,
            'type' => TransactionType::Transfer->value,
            'status' => TransactionStatus::Posted->value,
            'transaction_at' => $now->toIso8601String(),
            'posting_at' => $now->toIso8601String(),
            'description' => $description,
            'transfer_direction' => TransferDirection::Out->value,
            'transfer_counterpart_account_id' => $toAccount->getKey(),
            'transfer_reference' => 'manual:'.$idempotencyKey,
        ];

        try {
            $result = $this->transactionProcessor->process($fromAccount, $record);
        } catch (TransactionProcessingException $e) {
            return 'Transfer rejected: '.$e->getMessage();
        } catch (\Throwable $e) {
            return 'Error processing transfer: '.$e->getMessage();
        }

        return implode("\n", [
            'Recorded transfer:',
            '- From: '.$fromAccount->name,
            '- To: '.$toAccount->name,
            '- Amount: '.$amount->format(),
            '- Description: '.$description,
            '- Status: POSTED',
            '- Primary ID: '.$result->transaction->id,
        ]);
    }

    private function handlePostTransaction(User $owner, string $argumentString): string
    {
        $id = trim($argumentString);
        if ($id === '') {
            return 'Usage: /post_tx <transaction_id>';
        }

        $transaction = $this->resolveOwnerTransaction($owner, $id);
        if (! $transaction instanceof Transaction) {
            return $transaction;
        }

        if ($transaction->is_generated_counterpart) {
            return 'Update the primary transfer entry instead of the counterpart.';
        }

        if ($transaction->status !== TransactionStatus::Pending) {
            return "Transaction is already in status '{$transaction->status->value}'.";
        }

        $now = Carbon::now('UTC');
        $record = [
            'idempotency_key' => $transaction->idempotency_key,
            'amount_minor_units' => $transaction->amount_minor_units,
            'currency' => $transaction->currency,
            'currency_exponent' => $transaction->currency_exponent,
            'type' => $transaction->type->value,
            'status' => TransactionStatus::Posted->value,
            'transaction_at' => $transaction->transaction_at->toIso8601String(),
            'posting_at' => $now->toIso8601String(),
            'description' => $transaction->description,
            'merchant' => $transaction->merchant,
            'original_transaction_id' => $transaction->original_transaction_id,
        ];

        if ($transaction->type === TransactionType::Transfer) {
            $record['transfer_direction'] = $transaction->transfer_direction?->value;
            $record['transfer_counterpart_account_id'] = $transaction->transfer_counterpart_account_id;
            $record['transfer_reference'] = $transaction->transfer_reference;
        }

        try {
            $result = $this->transactionProcessor->process($transaction->account, $record);
        } catch (TransactionProcessingException $e) {
            return 'Update rejected: '.$e->getMessage();
        }

        return implode("\n", [
            'Transaction posted:',
            '- ID: '.$result->transaction->id,
            '- Description: '.($result->transaction->description ?? 'None'),
            '- Status: POSTED',
            '- Posting date: '.$now->format('Y-m-d'),
        ]);
    }

    private function handleReverseTransaction(User $owner, string $argumentString): string
    {
        $id = trim($argumentString);
        if ($id === '') {
            return 'Usage: /reverse_tx <transaction_id>';
        }

        $transaction = $this->resolveOwnerTransaction($owner, $id);
        if (! $transaction instanceof Transaction) {
            return $transaction;
        }

        if ($transaction->is_generated_counterpart) {
            return 'Update the primary transfer entry instead of the counterpart.';
        }

        if ($transaction->status !== TransactionStatus::Posted) {
            return "Only POSTED transactions can be reversed. Current status: '{$transaction->status->value}'.";
        }

        $record = [
            'idempotency_key' => $transaction->idempotency_key,
            'amount_minor_units' => $transaction->amount_minor_units,
            'currency' => $transaction->currency,
            'currency_exponent' => $transaction->currency_exponent,
            'type' => $transaction->type->value,
            'status' => TransactionStatus::Reversed->value,
            'transaction_at' => $transaction->transaction_at->toIso8601String(),
            'posting_at' => $transaction->posting_at?->toIso8601String(),
            'description' => $transaction->description,
            'merchant' => $transaction->merchant,
            'original_transaction_id' => $transaction->original_transaction_id,
        ];

        if ($transaction->type === TransactionType::Transfer) {
            $record['transfer_direction'] = $transaction->transfer_direction?->value;
            $record['transfer_counterpart_account_id'] = $transaction->transfer_counterpart_account_id;
            $record['transfer_reference'] = $transaction->transfer_reference;
        }

        try {
            $result = $this->transactionProcessor->process($transaction->account, $record);
        } catch (TransactionProcessingException $e) {
            return 'Reversal rejected: '.$e->getMessage();
        }

        return implode("\n", [
            'Transaction reversed:',
            '- ID: '.$result->transaction->id,
            '- Description: '.($result->transaction->description ?? 'None'),
            '- Status: REVERSED',
        ]);
    }

    private function resolveOwnerAccount(User $owner, string $identifier): Account|string
    {
        $trimmed = trim($identifier);
        if ($trimmed === '') {
            return 'Account identifier cannot be empty.';
        }

        $query = Account::query()
            ->where('owner_id', $owner->getKey())
            ->where('is_active', true);

        if (Str::isUuid($trimmed)) {
            $account = $query->whereKey($trimmed)->first();
        } else {
            $account = $query->whereRaw('LOWER(name) = ?', [mb_strtolower($trimmed)])->first();
        }

        if ($account === null) {
            return "Account '{$trimmed}' was not found among your active accounts. Use /accounts to see available accounts.";
        }

        return $account;
    }

    private function resolveOwnerTransaction(User $owner, string $identifier): Transaction|string
    {
        $trimmed = trim($identifier);
        if ($trimmed === '') {
            return 'Transaction ID cannot be empty.';
        }

        $query = Transaction::query()
            ->with('account')
            ->whereHas('account', fn ($q) => $q->where('owner_id', $owner->getKey()));

        if (Str::isUuid($trimmed)) {
            $tx = $query->whereKey($trimmed)->first();
        } else {
            $tx = $query->where('id', 'like', $trimmed.'%')->first();
        }

        if ($tx === null) {
            return "Transaction '{$trimmed}' was not found. Use /transactions to list your transactions.";
        }

        return $tx;
    }

    private function handleExpectedIncomeList(User $owner): string
    {
        $expectations = $this->incomeExpectations->forOwner($owner);

        if ($expectations->isEmpty()) {
            return 'Manual income expectations (assumptions only, excluded from actual balance and budget): none recorded.';
        }

        $lines = [
            'Manual income expectations (assumptions only, excluded from actual balance and budget):',
        ];

        foreach ($expectations as $expectation) {
            $amount = new Money(
                $expectation->amount_minor_units,
                $expectation->currency,
                $expectation->currency_exponent,
            );
            $lines[] = sprintf(
                '- %s: %s — %s [%s]',
                $expectation->expected_date,
                $expectation->description,
                $amount->format(),
                $expectation->status->value,
            );
        }

        return implode("\n", $lines);
    }

    private function handleCreateExpectedIncome(User $owner, string $argumentString): string
    {
        $tokens = $argumentString === '' ? [] : (preg_split('/\s+/u', $argumentString, 3) ?: []);

        if (count($tokens) < 3) {
            return 'Usage: /expect_income <YYYY-MM-DD> <amount> <description>';
        }

        try {
            [$currency, $exponent] = $this->resolveCurrency($owner, '');
            $amount = Money::fromDecimalString($tokens[1], $currency, $exponent);
            $expectation = $this->incomeExpectations->create($owner, $tokens[2], $amount, $tokens[0]);
        } catch (InvalidArgumentException|OverflowException $exception) {
            return 'Unable to record income expectation: '.$exception->getMessage();
        }

        $formattedAmount = new Money(
            $expectation->amount_minor_units,
            $expectation->currency,
            $expectation->currency_exponent,
        );

        return sprintf(
            'Recorded manual income expectation (assumption only, not in balance or budget): %s on %s — %s [%s]',
            $expectation->description,
            $expectation->expected_date,
            $formattedAmount->format(),
            $expectation->status->value,
        );
    }

    private function handleCompleteExpectedIncome(User $owner, string $argumentString): string
    {
        $id = trim($argumentString);

        if ($id === '') {
            return 'Usage: /complete_expected_income <expectation_id>';
        }

        try {
            $expectation = $this->incomeExpectations->complete($owner, $id);
        } catch (ModelNotFoundException) {
            return 'Income expectation not found.';
        }

        return sprintf(
            'Completed manual income expectation (assumption only, ledger unchanged): %s [%s]',
            $expectation->description,
            $expectation->status->value,
        );
    }

    private function handleNotifications(User $owner, string $argumentString): string
    {
        if ($argumentString === '') {
            $lines = ['Telegram notification preferences:'];

            foreach (NotificationCategory::cases() as $category) {
                $state = $this->notifications->isEnabled($owner, $category) ? 'ON' : 'OFF';
                $lines[] = "- {$category->value}: {$state}";
            }

            return implode("\n", $lines);
        }

        $tokens = preg_split('/\s+/u', $argumentString) ?: [];

        if (count($tokens) !== 2) {
            return 'Usage: /notifications [CATEGORY on|off]';
        }

        try {
            $category = NotificationCategory::from(strtoupper($tokens[0]));
        } catch (ValueError) {
            return 'Unknown notification category.';
        }

        $stateToken = strtolower($tokens[1]);

        if (! in_array($stateToken, ['on', 'off'], true)) {
            return 'Notification state must be "on" or "off".';
        }

        $preference = $this->notifications->setPreference($owner, $category, $stateToken === 'on');

        return sprintf(
            'Notification preference updated: %s is now %s.',
            $preference->category->value,
            $preference->is_enabled ? 'ON' : 'OFF',
        );
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function resolveCurrency(User $owner, string $argumentString): array
    {
        $currency = $argumentString !== ''
            ? strtoupper(trim($argumentString))
            : $this->defaultCurrency();

        if ($currency === 'AMD') {
            return ['AMD', 0];
        }

        $accountExponent = Account::query()
            ->where('owner_id', $owner->getKey())
            ->where('currency', $currency)
            ->value('currency_exponent');

        if ($accountExponent !== null) {
            return [$currency, (int) $accountExponent];
        }

        $budgetExponent = BudgetSetting::query()
            ->where('owner_id', $owner->getKey())
            ->where('currency', $currency)
            ->orderByDesc('revision')
            ->value('currency_exponent');

        if ($budgetExponent !== null) {
            return [$currency, (int) $budgetExponent];
        }

        return [$currency, 2];
    }

    private function defaultCurrency(): string
    {
        $configured = config('finance.currency', 'AMD');

        return is_string($configured) && $configured !== '' ? strtoupper($configured) : 'AMD';
    }

    /**
     * @param  list<string>  $lines
     */
    private function formatBudgetSummary(string $currency, int $planningDays, array $lines): string
    {
        return implode("\n", [
            "Budget summary ({$currency}, {$planningDays} days):",
            ...$lines,
        ]);
    }

    /**
     * @return list<string>
     */
    private function parseArguments(string $argumentString, int $limit = 0): array
    {
        $trimmed = trim($argumentString);
        if ($trimmed === '') {
            return [];
        }

        preg_match_all('/"([^"]+)"|\'([^\']+)\'|(\S+)/u', $trimmed, $matches, PREG_SET_ORDER);
        $tokens = [];
        foreach ($matches as $match) {
            $tokens[] = ($match[1] ?? '') !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
        }

        if ($limit > 0 && count($tokens) > $limit) {
            $head = array_slice($tokens, 0, $limit - 1);
            $tail = array_slice($tokens, $limit - 1);
            $head[] = implode(' ', $tail);

            return $head;
        }

        return $tokens;
    }

    private function helpText(): string
    {
        return implode("\n", [
            'Available commands:',
            '• /app — Open Finance Assistant Mini App',
            '• /widget — Send interactive Bot API 10.4 widget',
            '• /balance [currency] — Balance summary (posted, pending, credit)',
            '• /budget [currency] — Budget summary and spending limit',
            '• /accounts — List your accounts and balances',
            '• /create_account <name> <asset|credit> [opening_balance] — Create account',
            '• /transactions [limit] — List recent transactions',
            '• /expense <account> <amount> [description] — Record expense',
            '• /income <account> <amount> [description] — Record income',
            '• /transfer <from> <to> <amount> [description] — Transfer between accounts',
            '• /post_tx <id> — Confirm a pending transaction as posted',
            '• /reverse_tx <id> — Reverse a posted transaction',
            '• /set_budget <mandatory> <reserve> <savings> [days] — Set budget assumptions',
            '• /expected_income — List manual expected income assumptions',
            '• /expect_income <YYYY-MM-DD> <amount> <desc> — Record expected income',
            '• /complete_expected_income <id> — Complete expected income',
            '• /notifications [CATEGORY on|off] — Notification preferences',
            '• /help — Show this help message',
        ]);
    }
}
