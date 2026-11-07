<?php

namespace App\Domain\AiAssistant;

use App\Domain\Accounts\BalanceCalculator;
use App\Domain\Accounts\BudgetPlanningService;
use App\Domain\Income\IncomeExpectationService;
use App\Domain\Money\Money;
use App\Domain\Transactions\TransactionStatus;
use App\Models\AiCategorySuggestion;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class AiAssistantService
{
    public const DEFAULT_MIN_CONFIDENCE_PERCENT = 70;

    private const SQL_PATTERNS = [
        '/\bSELECT\b.+\bFROM\b/i',
        '/\bINSERT\s+INTO\b/i',
        '/\bUPDATE\s+\w+\s+SET\b/i',
        '/\bDELETE\s+FROM\b/i',
        '/\bDROP\s+(?:TABLE|DATABASE|SCHEMA|INDEX)\b/i',
        '/\bALTER\s+TABLE\b/i',
        '/\bTRUNCATE\s+TABLE\b/i',
        '/\bUNION\s+(?:ALL\s+)?SELECT\b/i',
        '/;\s*--/',
    ];

    public function __construct(
        private readonly AiAssistantProviderInterface $provider,
        private readonly BalanceCalculator $balances,
        private readonly BudgetPlanningService $budgets,
        private readonly IncomeExpectationService $incomeExpectations,
    ) {}

    public function suggestCategory(
        User $owner,
        Transaction $transaction,
        int $minimumConfidencePercent = self::DEFAULT_MIN_CONFIDENCE_PERCENT,
    ): AiCategorySuggestion {
        if ($minimumConfidencePercent < 1 || $minimumConfidencePercent > 100) {
            throw new AiBoundaryException('Minimum confidence threshold must be between 1 and 100.');
        }

        $ownedTransaction = Transaction::query()
            ->whereKey($transaction->getKey())
            ->whereHas('account', fn ($query) => $query->where('owner_id', $owner->getKey()))
            ->firstOrFail();

        $categories = TransactionCategory::query()
            ->where('is_active', true)
            ->orderBy('slug')
            ->get();

        if ($categories->isEmpty()) {
            throw new AiBoundaryException('No active transaction categories are available for suggestion.');
        }

        $context = new AiCategoryPromptContext(
            transactionType: $ownedTransaction->type->value,
            merchant: $this->sanitizePromptField($ownedTransaction->merchant),
            description: $this->sanitizePromptField($ownedTransaction->description),
            allowedCategories: $categories
                ->map(fn (TransactionCategory $category): array => [
                    'slug' => $category->slug,
                    'name' => $category->name,
                ])
                ->values()
                ->all(),
        );

        $proposal = $this->provider->proposeCategory($context);

        if ($proposal->extraFields !== []) {
            throw new AiBoundaryException('AI category proposal must not include unexpected mutation or query fields.');
        }

        if ($proposal->confidencePercent < 1 || $proposal->confidencePercent > 100) {
            throw new AiBoundaryException('AI confidence percent must be an integer between 1 and 100.');
        }

        if ($proposal->confidencePercent < $minimumConfidencePercent) {
            throw new AiBoundaryException('AI suggestion confidence is below the minimum review threshold.');
        }

        $rationale = trim($proposal->rationale);

        if ($rationale === '' || mb_strlen($rationale) > 255) {
            throw new AiBoundaryException('AI suggestion rationale must contain between 1 and 255 characters.');
        }

        $this->assertNoSqlOrSecrets($rationale);

        /** @var TransactionCategory|null $matchedCategory */
        $matchedCategory = $categories->firstWhere('slug', trim($proposal->categorySlug));

        if ($matchedCategory === null) {
            throw new AiBoundaryException('AI proposed an unknown or inactive transaction category.');
        }

        $suggestion = new AiCategorySuggestion([
            'transaction_id' => $ownedTransaction->getKey(),
            'suggested_category_id' => $matchedCategory->getKey(),
            'confidence_percent' => $proposal->confidencePercent,
            'status' => AiSuggestionStatus::PendingReview,
            'rationale' => $rationale,
            'reviewed_at' => null,
        ]);
        $suggestion->owner()->associate($owner);
        $suggestion->save();

        return $suggestion;
    }

    public function reviewSuggestion(User $owner, string $suggestionId, bool $approve): AiCategorySuggestion
    {
        return DB::transaction(function () use ($owner, $suggestionId, $approve): AiCategorySuggestion {
            $suggestion = AiCategorySuggestion::query()
                ->where('owner_id', $owner->getKey())
                ->whereKey($suggestionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($suggestion->status !== AiSuggestionStatus::PendingReview) {
                throw new AiBoundaryException('AI suggestion has already been reviewed.');
            }

            $transaction = Transaction::query()
                ->whereKey($suggestion->transaction_id)
                ->whereHas('account', fn ($query) => $query->where('owner_id', $owner->getKey()))
                ->lockForUpdate()
                ->firstOrFail();

            if ($approve) {
                if ($transaction->status !== TransactionStatus::Pending) {
                    throw new AiBoundaryException('Confirmed financial records cannot be modified by AI suggestions.');
                }

                $transaction->category_id = $suggestion->suggested_category_id;
                $transaction->save();
            }

            $suggestion->status = $approve ? AiSuggestionStatus::Approved : AiSuggestionStatus::Rejected;
            $suggestion->reviewed_at = Carbon::now('UTC');
            $suggestion->save();

            return $suggestion;
        });
    }

    public function assistQuery(User $owner, string $question, string $currency = 'AMD', int $currencyExponent = 0): AiQueryAssistanceResult
    {
        $normalizedQuestion = trim($question);

        if ($normalizedQuestion === '' || mb_strlen($normalizedQuestion) > 500) {
            throw new AiBoundaryException('Question must contain between 1 and 500 characters.');
        }

        $this->assertNoSqlOrSecrets($normalizedQuestion);

        $balanceSheet = $this->balances->calculateForOwner($owner->getKey(), $currency, $currencyExponent);

        $budgetFacts = null;
        try {
            $budgetSummary = $this->budgets->calculate($owner, $currency, $currencyExponent);
            $budgetFacts = [
                'planning_days' => $budgetSummary->planningDays,
                'available_funds' => $budgetSummary->availableFunds->format(),
                'daily_limit' => $budgetSummary->dailyLimit->format(),
                'mandatory_payments' => $budgetSummary->mandatoryPayments->format(),
                'protected_reserve' => $budgetSummary->protectedReserve->format(),
                'allocated_savings' => $budgetSummary->allocatedSavings->format(),
            ];
        } catch (ModelNotFoundException) {
            $budgetFacts = null;
        }

        $expectations = $this->incomeExpectations->forOwner($owner)
            ->where('currency', $currency)
            ->values()
            ->map(fn ($item): array => [
                'expected_date' => $item->expected_date,
                'description' => $item->description,
                'amount' => (new Money($item->amount_minor_units, $item->currency, $item->currency_exponent))->format(),
                'status' => $item->status->value,
                'classification' => 'MANUAL_ASSUMPTION_EXCLUDED_FROM_BALANCE_AND_BUDGET',
            ])
            ->all();

        $deterministicFacts = [
            'currency' => $currency,
            'balance' => [
                'posted_owned_funds' => $balanceSheet->ownedFunds->format(),
                'pending_owned_funds_change' => $balanceSheet->pendingOwnedFundsChange->format(),
                'pending_owned_funds_outflows' => $balanceSheet->pendingOwnedFundsDebits->format(),
                'credit_liabilities' => $balanceSheet->creditLiabilities->format(),
                'pending_credit_liability_change' => $balanceSheet->pendingCreditLiabilityChange->format(),
            ],
            'budget' => $budgetFacts,
            'manual_income_expectations' => $expectations,
        ];

        $context = new AiQueryPromptContext(
            question: $this->sanitizePromptField($normalizedQuestion) ?? '',
            currency: $currency,
            allowedIntents: array_map(fn (AiQueryIntent $case): string => $case->value, AiQueryIntent::cases()),
            deterministicFacts: $deterministicFacts,
        );

        $rawResponse = $this->provider->resolveQueryIntentAndExplain($context);

        if (isset($rawResponse['extra']) && $rawResponse['extra'] !== []) {
            throw new AiBoundaryException('AI query response must not include unexpected query or mutation fields.');
        }

        $intentValue = is_string($rawResponse['intent'] ?? null) ? trim($rawResponse['intent']) : '';
        $intent = AiQueryIntent::tryFrom($intentValue);

        if ($intent === null) {
            throw new AiBoundaryException('AI returned an unsupported query intent.');
        }

        $explanation = is_string($rawResponse['explanation'] ?? null) ? trim($rawResponse['explanation']) : '';

        if ($explanation === '' || mb_strlen($explanation) > 1000) {
            throw new AiBoundaryException('AI explanation must contain between 1 and 1000 characters.');
        }

        $this->assertNoSqlOrSecrets($explanation);

        return new AiQueryAssistanceResult(
            intent: $intent,
            explanation: $explanation,
            deterministicFacts: $deterministicFacts,
        );
    }

    private function sanitizePromptField(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        // Redact long digit sequences (e.g. card numbers/account numbers) before sending to AI.
        $redacted = (string) preg_replace('/\b(?:\d[ -]*?){12,19}\b/', '[REDACTED_NUMBER]', $trimmed);

        return mb_substr($redacted, 0, 255);
    }

    private function assertNoSqlOrSecrets(string $text): void
    {
        foreach (self::SQL_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                throw new AiBoundaryException('Arbitrary SQL or database query expressions are forbidden.');
            }
        }

        if (preg_match('/\b(?:password|cvv|pin|access_token|refresh_token|webhook_secret|bot_token)\b/i', $text) === 1) {
            throw new AiBoundaryException('Prompts and AI responses must not contain credentials or secret material.');
        }
    }
}

