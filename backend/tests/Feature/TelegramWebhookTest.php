<?php

namespace Tests\Feature;

use App\Domain\Accounts\AccountType;
use App\Domain\Accounts\BudgetPlanningService;
use App\Domain\Income\IncomeExpectationService;
use App\Domain\Income\IncomeExpectationStatus;
use App\Domain\Money\Money;
use App\Domain\Telegram\TelegramAuthorizationException;
use App\Domain\Telegram\TelegramOwnerLinkService;
use App\Domain\Transactions\TransactionStatus;
use App\Domain\Transactions\TransactionType;
use App\Models\Account;
use App\Models\BudgetSetting;
use App\Models\IncomeExpectation;
use App\Models\TelegramWebhookUpdate;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'test-webhook-secret-token-987654321';

    private const OWNER_TELEGRAM_ID = 777000111;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.webhook_secret' => self::WEBHOOK_SECRET,
            'services.telegram.owner_id' => self::OWNER_TELEGRAM_ID,
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.rate_limit_per_minute' => 30,
        ]);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);
    }

    public function test_rejects_requests_with_missing_invalid_or_unconfigured_secret_token(): void
    {
        $owner = $this->createLinkedOwner();

        $this->postJson('/api/v1/telegram/webhook', $this->updatePayload(100, '/balance'))
            ->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong-secret')
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(101, '/balance'))
            ->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);

        config(['services.telegram.webhook_secret' => '']);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(102, '/balance'))
            ->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);

        $this->assertSame(0, TelegramWebhookUpdate::query()->where('owner_id', $owner->id)->count());
    }

    public function test_rejects_non_owner_unlinked_owner_and_non_private_chats_without_auto_enrolling(): void
    {
        $owner = User::factory()->create();

        // Owner exists and matches TELEGRAM_OWNER_ID in payload, but has no verified link yet.
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(200, '/balance'))
            ->assertForbidden();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Account::query()->count());

        app(TelegramOwnerLinkService::class)->linkVerifiedOwner($owner, self::OWNER_TELEGRAM_ID);

        // Unauthorized Telegram user ID.
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(201, '/balance', fromId: 999888777, chatId: 999888777))
            ->assertForbidden();

        // Group chat with owner as sender must still be rejected.
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(
                202,
                '/balance',
                fromId: self::OWNER_TELEGRAM_ID,
                chatId: -100123456,
                chatType: 'supergroup',
            ))
            ->assertForbidden();

        try {
            app(TelegramOwnerLinkService::class)->linkVerifiedOwner($owner, 999888777);
            $this->fail('Linking a Telegram ID other than TELEGRAM_OWNER_ID must fail.');
        } catch (TelegramAuthorizationException) {
            $this->assertSame(0, TelegramWebhookUpdate::query()->count());
        }
    }

    public function test_validates_webhook_payload_shape_and_rejects_bot_or_malformed_updates(): void
    {
        $this->createLinkedOwner();

        foreach ([
            ['update_id' => 0, 'message' => ['from' => ['id' => self::OWNER_TELEGRAM_ID], 'chat' => ['id' => self::OWNER_TELEGRAM_ID, 'type' => 'private'], 'text' => '/balance']],
            ['update_id' => '300', 'message' => ['from' => ['id' => self::OWNER_TELEGRAM_ID], 'chat' => ['id' => self::OWNER_TELEGRAM_ID, 'type' => 'private'], 'text' => '/balance']],
            ['update_id' => 301, 'message' => ['from' => ['id' => self::OWNER_TELEGRAM_ID, 'is_bot' => true], 'chat' => ['id' => self::OWNER_TELEGRAM_ID, 'type' => 'private'], 'text' => '/balance']],
            ['update_id' => 302, 'message' => ['from' => ['id' => self::OWNER_TELEGRAM_ID], 'chat' => ['id' => self::OWNER_TELEGRAM_ID, 'type' => 'private'], 'text' => '   ']],
            ['update_id' => 303],
        ] as $invalidPayload) {
            $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
                ->postJson('/api/v1/telegram/webhook', $invalidPayload)
                ->assertStatus(422);
        }

        $this->assertSame(0, TelegramWebhookUpdate::query()->count());
    }

    public function test_replay_protection_deduplicates_update_ids_and_prevents_duplicate_mutations(): void
    {
        $owner = $this->createLinkedOwner();
        Account::factory()->for($owner, 'owner')->create([
            'opening_balance_minor_units' => 200_000,
        ]);

        $payload = $this->updatePayload(400, '/set_budget 15000 30000 5000 20');

        $first = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $payload)
            ->assertOk()
            ->assertJson([
                'status' => 'processed',
                'duplicate' => false,
                'update_id' => 400,
                'chat_id' => self::OWNER_TELEGRAM_ID,
                'command' => '/set_budget',
            ]);

        $second = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $payload)
            ->assertOk()
            ->assertJson([
                'status' => 'duplicate',
                'duplicate' => true,
                'update_id' => 400,
                'chat_id' => self::OWNER_TELEGRAM_ID,
                'command' => '/set_budget',
                'reply_text' => $first->json('reply_text'),
            ]);

        $this->assertSame($first->json('reply_text'), $second->json('reply_text'));
        $this->assertSame(1, BudgetSetting::query()->where('owner_id', $owner->id)->count());
        $this->assertSame(1, TelegramWebhookUpdate::query()->count());
        Http::assertSentCount(2);
    }

    public function test_sends_command_reply_to_the_authorized_private_chat(): void
    {
        $this->createLinkedOwner();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(450, '/help'))
            ->assertOk()
            ->assertJsonPath('status', 'processed');

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.telegram.org/bottest-bot-token/sendMessage'
            && $request->data()['chat_id'] === self::OWNER_TELEGRAM_ID
            && $request->data()['text'] !== '');
    }

    public function test_delivery_failure_returns_a_retryable_error_and_duplicate_updates_retry_delivery(): void
    {
        $this->createLinkedOwner();
        Http::swap(new Factory);
        Http::fake([
            'api.telegram.org/*' => Http::sequence()
                ->push(['ok' => false], 503)
                ->push(['ok' => true], 200),
        ]);
        $payload = $this->updatePayload(451, '/help');

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $payload)
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Telegram reply delivery failed.']);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'duplicate');

        Http::assertSentCount(2);
        $this->assertSame(1, TelegramWebhookUpdate::query()->where('update_id', 451)->count());
    }

    public function test_missing_bot_token_fails_delivery_without_exposing_credentials(): void
    {
        $this->createLinkedOwner();
        config(['services.telegram.bot_token' => '']);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(452, '/help'))
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Telegram reply delivery failed.']);
    }

    public function test_rate_limits_webhook_requests(): void
    {
        config(['services.telegram.rate_limit_per_minute' => 2]);
        $this->createLinkedOwner();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(501, '/help'))
            ->assertOk();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(502, '/help'))
            ->assertOk();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(503, '/help'))
            ->assertStatus(429);
    }

    public function test_commands_use_existing_financial_services_and_keep_categories_of_data_distinct(): void
    {
        $owner = $this->createLinkedOwner();
        $otherUser = User::factory()->create();

        $assetAccount = Account::factory()->for($owner, 'owner')->create([
            'type' => AccountType::Asset,
            'opening_balance_minor_units' => 150_000,
        ]);
        $creditAccount = Account::factory()->for($owner, 'owner')->create([
            'type' => AccountType::Credit,
            'opening_balance_minor_units' => 12_000,
            'credit_limit_minor_units' => 500_000,
        ]);
        Account::factory()->for($otherUser, 'owner')->create([
            'opening_balance_minor_units' => 999_999,
        ]);

        Transaction::factory()->for($assetAccount)->create([
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Pending,
            'amount_minor_units' => 10_000,
        ]);
        Transaction::factory()->for($assetAccount)->create([
            'type' => TransactionType::Income,
            'status' => TransactionStatus::Pending,
            'amount_minor_units' => 40_000,
        ]);
        Transaction::factory()->for($creditAccount)->create([
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Posted,
            'amount_minor_units' => 3_000,
        ]);

        app(BudgetPlanningService::class)->save(
            $owner,
            new Money(20_000, 'AMD', 0),
            new Money(30_000, 'AMD', 0),
            new Money(5_000, 'AMD', 0),
            25,
        );

        $foreignExpectation = app(IncomeExpectationService::class)->create(
            $otherUser,
            'Other salary',
            new Money(400_000, 'AMD', 0),
            '2026-03-10',
        );

        $expectResponse = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(601, '/expect_income 2026-03-15 250000 Consulting retainer'))
            ->assertOk();

        $this->assertStringContainsString('assumption only, not in balance or budget', $expectResponse->json('reply_text'));
        $this->assertStringContainsString('AMD 250,000', $expectResponse->json('reply_text'));

        $balanceResponse = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(602, '/balance@finance_bot'))
            ->assertOk();

        $balanceText = (string) $balanceResponse->json('reply_text');
        $this->assertStringContainsString('Posted balance: AMD 150,000', $balanceText);
        $this->assertStringContainsString('Pending change: AMD 30,000', $balanceText);
        $this->assertStringContainsString('Pending outflows: AMD 10,000', $balanceText);
        $this->assertStringContainsString('Credit liabilities: AMD 15,000', $balanceText);

        $budgetResponse = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(603, '/budget'))
            ->assertOk();

        // Available funds: 150,000 - 10,000 (pending debit) - 20,000 - 30,000 - 5,000 = 85,000. Daily limit over 25 days = 3,400.
        // Expected income (250,000) and credit limit (500,000) and pending inflow (40,000) are never counted!
        $budgetText = (string) $budgetResponse->json('reply_text');
        $this->assertStringContainsString('Budget summary (AMD, 25 days):', $budgetText);
        $this->assertStringContainsString('Available funds: AMD 85,000', $budgetText);
        $this->assertStringContainsString('Daily limit: AMD 3,400', $budgetText);
        $this->assertStringContainsString('Credit liabilities: AMD 15,000', $budgetText);

        $listIncomeResponse = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(604, '/expected_income'))
            ->assertOk();

        $this->assertStringContainsString('Consulting retainer — AMD 250,000 [PLANNED]', (string) $listIncomeResponse->json('reply_text'));
        $this->assertStringNotContainsString('Other salary', (string) $listIncomeResponse->json('reply_text'));

        // Cannot complete another user's income expectation.
        $foreignCompleteResponse = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(605, '/complete_expected_income '.$foreignExpectation->id))
            ->assertOk();

        $this->assertSame('Income expectation not found.', $foreignCompleteResponse->json('reply_text'));
        $this->assertSame(IncomeExpectationStatus::Planned, $foreignExpectation->fresh()->status);

        $ownerExpectation = IncomeExpectation::query()->where('owner_id', $owner->id)->firstOrFail();
        $completeResponse = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(606, '/complete_expected_income '.$ownerExpectation->id))
            ->assertOk();

        $this->assertStringContainsString('Completed manual income expectation', (string) $completeResponse->json('reply_text'));
        $this->assertSame(IncomeExpectationStatus::Completed, $ownerExpectation->fresh()->status);
    }

    private function createLinkedOwner(): User
    {
        $owner = User::factory()->create();
        app(TelegramOwnerLinkService::class)->linkVerifiedOwner($owner, self::OWNER_TELEGRAM_ID);

        return $owner;
    }

    /**
     * @return array<string, mixed>
     */
    private function updatePayload(
        int $updateId,
        string $text,
        int $fromId = self::OWNER_TELEGRAM_ID,
        int $chatId = self::OWNER_TELEGRAM_ID,
        string $chatType = 'private',
    ): array {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId * 10,
                'from' => [
                    'id' => $fromId,
                    'is_bot' => false,
                    'first_name' => 'Owner',
                ],
                'chat' => [
                    'id' => $chatId,
                    'type' => $chatType,
                ],
                'date' => 1762550000,
                'text' => $text,
            ],
        ];
    }
}
