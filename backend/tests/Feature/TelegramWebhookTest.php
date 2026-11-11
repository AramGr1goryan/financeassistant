<?php

namespace Tests\Feature;

use App\Domain\Accounts\AccountType;
use App\Domain\Accounts\BudgetPlanningService;
use App\Domain\Income\IncomeExpectationService;
use App\Domain\Income\IncomeExpectationStatus;
use App\Domain\Money\Money;
use App\Domain\Telegram\TelegramAuthorizationException;
use App\Domain\Telegram\TelegramFinanceWidget;
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

    public function test_widget_command_sends_a_private_interactive_widget_within_telegram_size_limits(): void
    {
        $this->createLinkedOwner();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(460, '/widget'))
            ->assertOk();

        $html = TelegramFinanceWidget::html();
        $this->assertLessThanOrEqual(32768, strlen($html));
        $compressed = gzcompress($html, 9);
        $this->assertNotFalse($compressed);
        $this->assertLessThanOrEqual(4096, strlen($compressed));
        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendWidget')
            && $request->data()['chat_id'] === self::OWNER_TELEGRAM_ID
            && $request->data()['html'] === $html
            && $request->data()['allow_queries'] === true);
    }

    public function test_app_command_opens_existing_sign_in_protected_dashboard(): void
    {
        $this->createLinkedOwner();
        config(['app.url' => 'https://finance.example.test']);

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(465, '/app'))
            ->assertOk();

        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request->data()['reply_markup']['inline_keyboard'][0][0]['web_app']['url'] === 'https://finance.example.test');

        config(['app.url' => 'http://example.test']);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(466, '/app'))
            ->assertStatus(503);
    }

    public function test_widget_queries_are_owner_only_and_return_ledger_balance_without_duplicate_mutations(): void
    {
        $owner = $this->createLinkedOwner();
        Account::factory()->for($owner, 'owner')->create(['opening_balance_minor_units' => 12345]);
        $payload = $this->widgetPayload(461, 'balance');

        foreach (range(1, 2) as $attempt) {
            $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
                ->postJson('/api/v1/telegram/webhook', $payload)
                ->assertOk()
                ->assertJsonPath('duplicate', $attempt === 2);
        }

        $this->assertSame(1, TelegramWebhookUpdate::query()->where('update_id', 461)->count());
        Http::assertSent(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/answerWidgetQuery')
            && $request->data()['widget_query_id'] === 'query-461'
            && str_contains($request->data()['data'], 'Posted balance: AMD 12,345'));

        $payload = $this->widgetPayload(462, 'budget');
        $payload['widget_query']['from']['id'] = 999;
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $payload)->assertForbidden();

        $payload = $this->widgetPayload(463, 'balance');
        $payload['widget_query']['chat']['type'] = 'group';
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $payload)->assertForbidden();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->widgetPayload(464, '/set_budget 0 0 0'))
            ->assertStatus(422);
        $this->assertSame(0, BudgetSetting::query()->count());
    }

    public function test_widget_rejects_missing_secret_and_non_private_or_non_data_queries(): void
    {
        $this->createLinkedOwner();
        $payload = $this->widgetPayload(467, 'balance');

        $this->postJson('/api/v1/telegram/webhook', $payload)->assertForbidden();

        unset($payload['widget_query']['chat']);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $payload)->assertStatus(422);

        $payload = $this->widgetPayload(468, 'balance');
        $payload['widget_query']['type'] = ['type' => 'sign_data'];
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $payload)->assertStatus(422);

        Http::assertNothingSent();
        $this->assertSame(0, TelegramWebhookUpdate::query()->count());
    }

    public function test_widget_query_delivery_failure_is_retryable(): void
    {
        $this->createLinkedOwner();
        Http::swap(new Factory);
        Http::fake([
            'api.telegram.org/*' => Http::sequence()
                ->push(['ok' => false], 503)
                ->push(['ok' => true], 200),
        ]);
        $payload = $this->widgetPayload(469, 'budget');

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $payload)->assertStatus(503);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $payload)->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, TelegramWebhookUpdate::query()->where('update_id', 469)->count());
        Http::assertSentCount(2);
    }

    /** @return array<string, mixed> */
    private function widgetPayload(int $updateId, string $data): array
    {
        return [
            'update_id' => $updateId,
            'widget_query' => [
                'id' => 'query-'.$updateId,
                'from' => ['id' => self::OWNER_TELEGRAM_ID, 'is_bot' => false],
                'chat' => ['id' => self::OWNER_TELEGRAM_ID, 'type' => 'private'],
                'message_id' => 10,
                'chat_instance' => 'test',
                'type' => ['type' => 'data'],
                'data' => $data,
            ],
        ];
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

    public function test_accounts_command_and_create_account_command(): void
    {
        $owner = $this->createLinkedOwner();

        // 1. Initial list when no accounts exist
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(701, '/accounts'))
            ->assertOk();
        $this->assertStringContainsString('No active accounts found', (string) $res->json('reply_text'));

        // 2. Create account validation errors
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(702, '/create_account'))
            ->assertOk();
        $this->assertStringContainsString('Usage: /create_account', (string) $res->json('reply_text'));

        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(703, '/create_account Checking invalid_type'))
            ->assertOk();
        $this->assertStringContainsString('Invalid account type', (string) $res->json('reply_text'));

        // 3. Create valid asset account
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(704, '/create_account Checking asset 100000'))
            ->assertOk();
        $this->assertStringContainsString('Account created successfully', (string) $res->json('reply_text'));
        $this->assertStringContainsString('Checking', (string) $res->json('reply_text'));
        $this->assertStringContainsString('AMD 100,000', (string) $res->json('reply_text'));

        // 4. Duplicate name rejected
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(705, '/create_account Checking asset 5000'))
            ->assertOk();
        $this->assertStringContainsString('already exists', (string) $res->json('reply_text'));

        // 5. Create credit account
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(706, '/create_account "Credit Card" credit 25000'))
            ->assertOk();
        $this->assertStringContainsString('Account created successfully', (string) $res->json('reply_text'));

        // 6. List accounts
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(707, '/accounts'))
            ->assertOk();
        $text = (string) $res->json('reply_text');
        $this->assertStringContainsString('Accounts (AMD):', $text);
        $this->assertStringContainsString('Checking (ASSET): AMD 100,000', $text);
        $this->assertStringContainsString('Credit Card (CREDIT): Liability AMD 25,000', $text);
    }

    public function test_transactions_expense_income_and_transfer_commands(): void
    {
        $owner = $this->createLinkedOwner();

        $accountA = Account::factory()->for($owner, 'owner')->create([
            'name' => 'Main',
            'type' => AccountType::Asset,
            'currency' => 'AMD',
            'currency_exponent' => 0,
            'opening_balance_minor_units' => 200_000,
        ]);
        $accountB = Account::factory()->for($owner, 'owner')->create([
            'name' => 'Savings',
            'type' => AccountType::Asset,
            'currency' => 'AMD',
            'currency_exponent' => 0,
            'opening_balance_minor_units' => 50_000,
        ]);

        // 1. Expense
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(801, '/expense Main 15000 Groceries'))
            ->assertOk();
        $this->assertStringContainsString('Recorded expense:', (string) $res->json('reply_text'));
        $this->assertStringContainsString('AMD 15,000', (string) $res->json('reply_text'));

        // 2. Income
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(802, '/income Main 80000 Consulting'))
            ->assertOk();
        $this->assertStringContainsString('Recorded income:', (string) $res->json('reply_text'));
        $this->assertStringContainsString('AMD 80,000', (string) $res->json('reply_text'));

        // 3. Transfer
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(803, '/transfer Main Savings 30000 Reserve fund'))
            ->assertOk();
        $this->assertStringContainsString('Recorded transfer:', (string) $res->json('reply_text'));
        $this->assertStringContainsString('From: Main', (string) $res->json('reply_text'));
        $this->assertStringContainsString('To: Savings', (string) $res->json('reply_text'));
        $this->assertStringContainsString('AMD 30,000', (string) $res->json('reply_text'));

        // 4. List transactions
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(804, '/transactions'))
            ->assertOk();
        $text = (string) $res->json('reply_text');
        $this->assertStringContainsString('Recent transactions', $text);
        $this->assertStringContainsString('Groceries', $text);
        $this->assertStringContainsString('Consulting', $text);
        $this->assertStringContainsString('Reserve fund', $text);

        // 5. Verify balances:
        // Main: 200,000 - 15,000 + 80,000 - 30,000 = 235,000 AMD.
        // Savings: 50,000 + 30,000 = 80,000 AMD.
        // Total posted: 315,000 AMD.
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(805, '/balance'))
            ->assertOk();
        $this->assertStringContainsString('Posted balance: AMD 315,000', (string) $res->json('reply_text'));
    }

    public function test_post_and_reverse_transaction_commands(): void
    {
        $owner = $this->createLinkedOwner();

        $account = Account::factory()->for($owner, 'owner')->create([
            'name' => 'Card',
            'type' => AccountType::Asset,
            'currency' => 'AMD',
            'currency_exponent' => 0,
            'opening_balance_minor_units' => 100_000,
        ]);

        $pendingTx = Transaction::factory()->for($account)->create([
            'type' => TransactionType::Expense,
            'status' => TransactionStatus::Pending,
            'amount_minor_units' => 12_000,
            'description' => 'Pending Restaurant',
        ]);

        // 1. Post pending transaction
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(901, '/post_tx '.$pendingTx->id))
            ->assertOk();
        $this->assertStringContainsString('Transaction posted:', (string) $res->json('reply_text'));
        $this->assertStringContainsString('POSTED', (string) $res->json('reply_text'));
        $this->assertSame(TransactionStatus::Posted, $pendingTx->fresh()->status);

        // 2. Attempting to post again fails
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(902, '/post_tx '.$pendingTx->id))
            ->assertOk();
        $this->assertStringContainsString("already in status 'POSTED'", (string) $res->json('reply_text'));

        // 3. Reverse posted transaction
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(903, '/reverse_tx '.$pendingTx->id))
            ->assertOk();
        $this->assertStringContainsString('Transaction reversed:', (string) $res->json('reply_text'));
        $this->assertStringContainsString('REVERSED', (string) $res->json('reply_text'));
        $this->assertSame(TransactionStatus::Reversed, $pendingTx->fresh()->status);
    }

    public function test_widget_query_accounts(): void
    {
        $owner = $this->createLinkedOwner();

        Account::factory()->for($owner, 'owner')->create([
            'name' => 'Cash Box',
            'type' => AccountType::Asset,
            'currency' => 'AMD',
            'currency_exponent' => 0,
            'opening_balance_minor_units' => 45_000,
        ]);

        $payload = $this->widgetPayload(950, 'accounts');

        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $payload)
            ->assertOk();

        $this->assertStringContainsString('Accounts (AMD):', (string) $res->json('reply_text'));
        $this->assertStringContainsString('Cash Box', (string) $res->json('reply_text'));
        $this->assertStringContainsString('AMD 45,000', (string) $res->json('reply_text'));
    }

    public function test_foreign_user_cannot_access_accounts_or_create_transactions(): void
    {
        $owner = $this->createLinkedOwner();
        $otherUser = User::factory()->create();

        $foreignAccount = Account::factory()->for($otherUser, 'owner')->create([
            'name' => 'Foreign',
            'type' => AccountType::Asset,
            'currency' => 'AMD',
            'currency_exponent' => 0,
            'opening_balance_minor_units' => 500_000,
        ]);

        // Attempting to spend from other user's account
        $res = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', self::WEBHOOK_SECRET)
            ->postJson('/api/v1/telegram/webhook', $this->updatePayload(960, '/expense Foreign 10000 Theft'))
            ->assertOk();

        $this->assertStringContainsString("not found among your active accounts", (string) $res->json('reply_text'));
        $this->assertSame(0, Transaction::query()->where('account_id', $foreignAccount->id)->count());
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
