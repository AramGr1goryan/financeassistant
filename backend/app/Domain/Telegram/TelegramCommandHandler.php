<?php

namespace App\Domain\Telegram;

use App\Domain\Accounts\BalanceCalculator;
use App\Domain\Accounts\BudgetCalculator;
use App\Domain\Accounts\BudgetPlanningService;
use App\Domain\Income\IncomeExpectationService;
use App\Domain\Money\Money;
use App\Domain\Notifications\NotificationCategory;
use App\Domain\Notifications\NotificationService;
use App\Models\Account;
use App\Models\BudgetSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
            '/balance' => new TelegramCommandResult($command, $this->handleBalance($owner, $argumentString)),
            '/budget' => new TelegramCommandResult($command, $this->handleBudget($owner, $argumentString)),
            '/set_budget' => new TelegramCommandResult($command, $this->handleSetBudget($owner, $argumentString)),
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

    private function helpText(): string
    {
        return 'Available commands: /balance, /budget, /set_budget, /expected_income, /expect_income, /complete_expected_income, /notifications, /help';
    }
}
