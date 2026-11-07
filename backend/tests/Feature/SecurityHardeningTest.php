<?php

namespace Tests\Feature;

use App\Domain\Accounts\BalanceCalculator;
use App\Domain\Accounts\BudgetPlanningService;
use App\Domain\AiAssistant\AiAssistantProviderInterface;
use App\Domain\AiAssistant\AiAssistantService;
use App\Domain\AiAssistant\AiCategoryPromptContext;
use App\Domain\AiAssistant\AiCategoryProposal;
use App\Domain\AiAssistant\AiQueryPromptContext;
use App\Domain\Income\IncomeExpectationService;
use App\Domain\Money\Money;
use App\Domain\Notifications\NotificationCategory;
use App\Domain\Notifications\NotificationService;
use App\Domain\Telegram\TelegramOwnerLinkService;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_and_api_responses_never_disclose_secrets_or_tokens(): void
    {
        $botToken = '999999:SUPER_SECRET_TELEGRAM_BOT_TOKEN';
        $webhookSecret = 'SUPER_SECRET_WEBHOOK_HEADER_VALUE';

        config([
            'services.telegram.bot_token' => $botToken,
            'services.telegram.webhook_secret' => $webhookSecret,
            'services.telegram.owner_id' => 111222333,
        ]);

        $owner = User::factory()->create();
        app(TelegramOwnerLinkService::class)->linkVerifiedOwner($owner, 111222333);

        $forbiddenResponse = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'invalid-token')
            ->postJson('/api/v1/telegram/webhook', [
                'update_id' => 1,
                'message' => [
                    'from' => ['id' => 111222333],
                    'chat' => ['id' => 111222333, 'type' => 'private'],
                    'text' => '/balance',
                ],
            ])
            ->assertForbidden();

        $this->assertStringNotContainsString($botToken, $forbiddenResponse->getContent());
        $this->assertStringNotContainsString($webhookSecret, $forbiddenResponse->getContent());

        $okResponse = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $webhookSecret)
            ->postJson('/api/v1/telegram/webhook', [
                'update_id' => 2,
                'message' => [
                    'from' => ['id' => 111222333],
                    'chat' => ['id' => 111222333, 'type' => 'private'],
                    'text' => '/balance',
                ],
            ])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertStringNotContainsString($botToken, $okResponse->getContent());
        $this->assertStringNotContainsString($webhookSecret, $okResponse->getContent());
    }

    public function test_phase_five_and_six_migrations_emit_postgres_check_constraints(): void
    {
        $previousConnection = \Illuminate\Support\Facades\DB::getDefaultConnection();
        \Illuminate\Support\Facades\DB::setDefaultConnection('pgsql');

        try {
            foreach ([
                '2026_11_08_020000_create_telegram_webhook_tables.php' => 'telegram_owner_links_values_check',
                '2026_11_08_020100_create_notifications_tables.php' => 'notifications_values_check',
                '2026_11_08_020200_create_ai_category_suggestions_table.php' => 'ai_category_suggestions_values_check',
            ] as $filename => $expectedConstraint) {
                $migration = require database_path('migrations/'.$filename);
                $queries = \Illuminate\Support\Facades\DB::connection('pgsql')->pretend(fn () => $migration->up());
                $sql = implode("\n", array_column($queries, 'query'));

                $this->assertStringContainsString($expectedConstraint, $sql, $filename);
            }
        } finally {
            \Illuminate\Support\Facades\DB::setDefaultConnection($previousConnection);
        }
    }

    public function test_cross_owner_isolation_holds_across_balances_budgets_expectations_notifications_and_ai(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        $accountA = Account::factory()->for($ownerA, 'owner')->create([
            'opening_balance_minor_units' => 250_000,
        ]);
        $accountB = Account::factory()->for($ownerB, 'owner')->create([
            'opening_balance_minor_units' => 900_000,
        ]);

        app(BudgetPlanningService::class)->save(
            $ownerA,
            new Money(10_000, 'AMD', 0),
            new Money(20_000, 'AMD', 0),
            new Money(5_000, 'AMD', 0),
        );
        app(IncomeExpectationService::class)->create(
            $ownerB,
            'Owner B private contract',
            new Money(500_000, 'AMD', 0),
            '2026-05-01',
        );
        app(NotificationService::class)->setPreference(
            $ownerB,
            NotificationCategory::BudgetThreshold,
            false,
        );

        $sheetA = app(BalanceCalculator::class)->calculateForOwner($ownerA->id, 'AMD', 0);
        $this->assertSame(250_000, $sheetA->ownedFunds->minorUnits);

        $this->assertCount(0, app(IncomeExpectationService::class)->forOwner($ownerA));
        $this->assertTrue(app(NotificationService::class)->isEnabled($ownerA, NotificationCategory::BudgetThreshold));

        TransactionCategory::factory()->create(['slug' => 'general', 'name' => 'General', 'is_active' => true]);
        $txB = Transaction::factory()->for($accountB)->create();

        $this->app->instance(AiAssistantProviderInterface::class, new class implements AiAssistantProviderInterface
        {
            public function proposeCategory(AiCategoryPromptContext $context): AiCategoryProposal
            {
                return new AiCategoryProposal('general', 90, 'Matched');
            }

            public function resolveQueryIntentAndExplain(AiQueryPromptContext $context): array
            {
                return ['intent' => 'BALANCE_SUMMARY', 'explanation' => 'OK'];
            }
        });

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(AiAssistantService::class)->suggestCategory($ownerA, $txB);
    }
}

