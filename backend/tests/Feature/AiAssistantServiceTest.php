<?php

namespace Tests\Feature;

use App\Domain\Accounts\BudgetPlanningService;
use App\Domain\AiAssistant\AiAssistantProviderInterface;
use App\Domain\AiAssistant\AiAssistantService;
use App\Domain\AiAssistant\AiBoundaryException;
use App\Domain\AiAssistant\AiCategoryPromptContext;
use App\Domain\AiAssistant\AiCategoryProposal;
use App\Domain\AiAssistant\AiQueryIntent;
use App\Domain\AiAssistant\AiQueryPromptContext;
use App\Domain\AiAssistant\AiSuggestionStatus;
use App\Domain\Income\IncomeExpectationService;
use App\Domain\Money\Money;
use App\Domain\Transactions\TransactionStatus;
use App\Domain\Transactions\TransactionType;
use App\Models\Account;
use App\Models\AiCategorySuggestion;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAssistantServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_ai_provider_is_optional_and_fails_closed(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->for($owner, 'owner')->create();
        TransactionCategory::factory()->create(['slug' => 'groceries', 'name' => 'Groceries', 'is_active' => true]);
        $transaction = Transaction::factory()->for($account)->create();

        $this->expectException(AiBoundaryException::class);

        app(AiAssistantService::class)->suggestCategory($owner, $transaction);
    }

    public function test_category_suggestion_sanitizes_prompt_requires_confidence_and_never_silently_modifies_records(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $account = Account::factory()->for($owner, 'owner')->create();
        $groceries = TransactionCategory::factory()->create([
            'slug' => 'groceries',
            'name' => 'Groceries',
            'is_active' => true,
        ]);
        TransactionCategory::factory()->create([
            'slug' => 'inactive-category',
            'name' => 'Inactive',
            'is_active' => false,
        ]);

        $postedTransaction = Transaction::factory()->for($account)->create([
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Posted,
            'amount_minor_units' => 14_500,
            'merchant' => 'Yerevan City Card 4111 2222 3333 4444',
            'description' => 'Supermarket purchase 4111222233334444',
            'category_id' => null,
            'metadata' => ['provider_raw_id' => 'internal-secret-ref'],
        ]);

        $pendingTransaction = Transaction::factory()->for($account)->create([
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Pending,
            'amount_minor_units' => 8_200,
            'merchant' => 'Green Market',
            'description' => 'Fresh produce',
            'category_id' => null,
        ]);

        $capturedContext = null;
        $this->app->instance(AiAssistantProviderInterface::class, new class($capturedContext) implements AiAssistantProviderInterface
        {
            public function __construct(public ?AiCategoryPromptContext &$captured) {}

            public function proposeCategory(AiCategoryPromptContext $context): AiCategoryProposal
            {
                $this->captured = $context;

                return new AiCategoryProposal(
                    categorySlug: 'groceries',
                    confidencePercent: 88,
                    rationale: 'Matched supermarket merchant.',
                );
            }

            public function resolveQueryIntentAndExplain(AiQueryPromptContext $context): array
            {
                return ['intent' => 'BALANCE_SUMMARY', 'explanation' => 'Summary'];
            }
        });

        $service = app(AiAssistantService::class);
        $postedSuggestion = $service->suggestCategory($owner, $postedTransaction);

        $this->assertNotNull($capturedContext);
        $this->assertStringNotContainsString('4111', (string) $capturedContext->merchant);
        $this->assertStringContainsString('[REDACTED_NUMBER]', (string) $capturedContext->merchant);
        $this->assertStringNotContainsString('4111', (string) $capturedContext->description);
        $this->assertSame([['slug' => 'groceries', 'name' => 'Groceries']], $capturedContext->allowedCategories);

        // Creating a suggestion never modifies the transaction record.
        $this->assertSame(AiSuggestionStatus::PendingReview, $postedSuggestion->status);
        $this->assertSame(88, $postedSuggestion->confidence_percent);
        $this->assertNull($postedTransaction->fresh()->category_id);
        $this->assertSame(14_500, $postedTransaction->fresh()->amount_minor_units);

        // Confirmed (POSTED) records must never be modified even during review approval.
        try {
            $service->reviewSuggestion($owner, $postedSuggestion->id, true);
            $this->fail('Confirmed POSTED transactions must not be modified by AI suggestions.');
        } catch (AiBoundaryException) {
            $this->assertNull($postedTransaction->fresh()->category_id);
            $this->assertSame(AiSuggestionStatus::PendingReview, $postedSuggestion->fresh()->status);
        }

        // Rejecting a suggestion on a posted record records the rejection without modifying the transaction.
        $rejected = $service->reviewSuggestion($owner, $postedSuggestion->id, false);
        $this->assertSame(AiSuggestionStatus::Rejected, $rejected->status);
        $this->assertNotNull($rejected->reviewed_at);
        $this->assertNull($postedTransaction->fresh()->category_id);

        // Pending transaction suggestion requires explicit owner review before category_id changes.
        $pendingSuggestion = $service->suggestCategory($owner, $pendingTransaction);
        $this->assertNull($pendingTransaction->fresh()->category_id);

        // Another user cannot review the owner's suggestion.
        try {
            $service->reviewSuggestion($other, $pendingSuggestion->id, true);
            $this->fail('Another user must not be able to review the suggestion.');
        } catch (ModelNotFoundException) {
            $this->assertNull($pendingTransaction->fresh()->category_id);
        }

        $approved = $service->reviewSuggestion($owner, $pendingSuggestion->id, true);
        $this->assertSame(AiSuggestionStatus::Approved, $approved->status);
        $this->assertSame($groceries->id, $pendingTransaction->fresh()->category_id);
        $this->assertSame(8_200, $pendingTransaction->fresh()->amount_minor_units);
    }

    public function test_rejects_low_confidence_unknown_categories_sql_expressions_and_mutation_fields(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->for($owner, 'owner')->create();
        TransactionCategory::factory()->create(['slug' => 'groceries', 'name' => 'Groceries', 'is_active' => true]);
        $transaction = Transaction::factory()->for($account)->create([
            'status' => TransactionStatus::Pending,
            'amount_minor_units' => 10_000,
        ]);

        foreach ([
            new AiCategoryProposal('groceries', 55, 'Too low confidence'),
            new AiCategoryProposal('groceries', 0, 'Invalid zero confidence'),
            new AiCategoryProposal('groceries', 101, 'Invalid >100 confidence'),
            new AiCategoryProposal('nonexistent', 90, 'Unknown category'),
            new AiCategoryProposal('groceries', 90, 'SELECT * FROM transactions WHERE id = 1'),
            new AiCategoryProposal('groceries', 90, 'DROP TABLE accounts; --'),
            new AiCategoryProposal('groceries', 90, 'Valid rationale', ['amount_minor_units' => 1]),
        ] as $invalidProposal) {
            $this->app->instance(AiAssistantProviderInterface::class, new class($invalidProposal) implements AiAssistantProviderInterface
            {
                public function __construct(private readonly AiCategoryProposal $proposal) {}

                public function proposeCategory(AiCategoryPromptContext $context): AiCategoryProposal
                {
                    return $this->proposal;
                }

                public function resolveQueryIntentAndExplain(AiQueryPromptContext $context): array
                {
                    return ['intent' => 'BALANCE_SUMMARY', 'explanation' => 'OK'];
                }
            });

            try {
                app(AiAssistantService::class)->suggestCategory($owner, $transaction);
                $this->fail('Untrusted or low-confidence AI proposal must be rejected.');
            } catch (AiBoundaryException) {
                $this->assertSame(0, AiCategorySuggestion::query()->count());
                $this->assertNull($transaction->fresh()->category_id);
                $this->assertSame(10_000, $transaction->fresh()->amount_minor_units);
            }
        }
    }

    public function test_natural_language_query_assistance_uses_deterministic_services_and_blocks_arbitrary_queries(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $ownerAccount = Account::factory()->for($owner, 'owner')->create([
            'opening_balance_minor_units' => 120_000,
        ]);
        Account::factory()->for($other, 'owner')->create([
            'opening_balance_minor_units' => 999_999,
        ]);
        Transaction::factory()->for($ownerAccount)->create([
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Pending,
            'amount_minor_units' => 10_000,
        ]);

        app(BudgetPlanningService::class)->save(
            $owner,
            new Money(20_000, 'AMD', 0),
            new Money(30_000, 'AMD', 0),
            new Money(0, 'AMD', 0),
            30,
        );
        app(IncomeExpectationService::class)->create(
            $owner,
            'Expected bonus',
            new Money(150_000, 'AMD', 0),
            '2026-04-01',
        );

        $capturedQueryContext = null;
        $this->app->instance(AiAssistantProviderInterface::class, new class($capturedQueryContext) implements AiAssistantProviderInterface
        {
            public function __construct(public ?AiQueryPromptContext &$captured) {}

            public function proposeCategory(AiCategoryPromptContext $context): AiCategoryProposal
            {
                return new AiCategoryProposal('groceries', 90, 'OK');
            }

            public function resolveQueryIntentAndExplain(AiQueryPromptContext $context): array
            {
                $this->captured = $context;

                return [
                    'intent' => 'BUDGET_SUMMARY',
                    'explanation' => 'Your available spending funds are AMD 60,000 (AMD 2,000/day over 30 days). Manual income expectations are excluded.',
                ];
            }
        });

        $service = app(AiAssistantService::class);
        $result = $service->assistQuery($owner, 'How much can I safely spend per day this month?');

        $this->assertSame(AiQueryIntent::BudgetSummary, $result->intent);
        $this->assertSame('AMD 120,000', $result->deterministicFacts['balance']['posted_owned_funds']);
        $this->assertSame('AMD 10,000', $result->deterministicFacts['balance']['pending_owned_funds_outflows']);
        $this->assertSame('AMD 60,000', $result->deterministicFacts['budget']['available_funds']);
        $this->assertSame('AMD 2,000', $result->deterministicFacts['budget']['daily_limit']);
        $this->assertSame(
            'MANUAL_ASSUMPTION_EXCLUDED_FROM_BALANCE_AND_BUDGET',
            $result->deterministicFacts['manual_income_expectations'][0]['classification'],
        );
        $this->assertNotNull($capturedQueryContext);

        // SQL in user prompt is blocked before calling provider.
        foreach ([
            'SELECT * FROM transactions WHERE account_id = 1',
            'DROP TABLE transactions; --',
            'DELETE FROM accounts WHERE 1=1',
            'What is my password or access_token?',
        ] as $forbiddenPrompt) {
            try {
                $service->assistQuery($owner, $forbiddenPrompt);
                $this->fail('Forbidden SQL or credential prompt must be rejected.');
            } catch (AiBoundaryException) {
                $this->assertTrue(true);
            }
        }

        // SQL or unsupported intent or extra mutation fields in AI output are blocked.
        foreach ([
            ['intent' => 'EXECUTE_SQL', 'explanation' => 'Done'],
            ['intent' => 'BUDGET_SUMMARY', 'explanation' => 'SELECT * FROM users'],
            ['intent' => 'BUDGET_SUMMARY', 'explanation' => 'Valid', 'extra' => ['sql' => 'UPDATE accounts SET opening_balance_minor_units = 0']],
        ] as $unsafeAiResponse) {
            $this->app->instance(AiAssistantProviderInterface::class, new class($unsafeAiResponse) implements AiAssistantProviderInterface
            {
                /**
                 * @param  array<string, mixed>  $response
                 */
                public function __construct(private readonly array $response) {}

                public function proposeCategory(AiCategoryPromptContext $context): AiCategoryProposal
                {
                    return new AiCategoryProposal('groceries', 90, 'OK');
                }

                public function resolveQueryIntentAndExplain(AiQueryPromptContext $context): array
                {
                    return $this->response;
                }
            });

            try {
                app(AiAssistantService::class)->assistQuery($owner, 'Show my budget');
                $this->fail('Untrusted AI query response must be rejected.');
            } catch (AiBoundaryException) {
                $this->assertTrue(true);
            }
        }
    }
}

