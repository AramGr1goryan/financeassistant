<?php

namespace Tests\Feature;

use App\Domain\Telegram\TelegramOwnerLinkService;
use App\Models\TelegramOwnerLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramManagementTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '123456789:ABCdefGHIjklMNOpqrsTUVwxyz';
    private const WEBHOOK_SECRET = 'secret-test-token-value';
    private const OWNER_TELEGRAM_ID = 888777666;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram.bot_token' => self::BOT_TOKEN,
            'services.telegram.webhook_secret' => self::WEBHOOK_SECRET,
            'services.telegram.owner_id' => self::OWNER_TELEGRAM_ID,
            'app.url' => 'https://financeassistant.test',
        ]);
    }

    public function test_guest_cannot_access_telegram_management_endpoints(): void
    {
        $this->getJson('/api/v1/telegram/owner-status')->assertUnauthorized();
        $this->postJson('/api/v1/telegram/link-owner')->assertUnauthorized();
        $this->postJson('/api/v1/telegram/register-webhook')->assertUnauthorized();
        $this->getJson('/api/v1/telegram/webhook-info')->assertUnauthorized();
    }

    public function test_authenticated_owner_can_check_status_and_link_configured_telegram_id(): void
    {
        $owner = User::factory()->create();

        // Check initial status: unlinked
        $statusRes = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/telegram/owner-status')
            ->assertOk();
        $this->assertFalse($statusRes->json('is_linked'));
        $this->assertTrue($statusRes->json('configured_id_set'));

        // Link owner
        $linkRes = $this->actingAs($owner, 'web')
            ->postJson('/api/v1/telegram/link-owner')
            ->assertOk();
        $this->assertSame(self::OWNER_TELEGRAM_ID, $linkRes->json('telegram_user_id'));

        $this->assertSame(1, TelegramOwnerLink::query()->where('owner_id', $owner->id)->count());

        // Check status again: linked
        $statusRes = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/telegram/owner-status')
            ->assertOk();
        $this->assertTrue($statusRes->json('is_linked'));
        $this->assertSame(self::OWNER_TELEGRAM_ID, $statusRes->json('telegram_user_id'));
    }

    public function test_logging_in_via_session_auto_links_unlinked_owner(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'password' => 'secret-password-123',
        ]);

        $this->assertSame(0, TelegramOwnerLink::query()->count());

        $this->postJson('/api/v1/session', [
            'email' => 'owner@example.com',
            'password' => 'secret-password-123',
        ])->assertNoContent();

        $this->assertSame(1, TelegramOwnerLink::query()->where('owner_id', $user->id)->count());
        $link = TelegramOwnerLink::query()->where('owner_id', $user->id)->firstOrFail();
        $this->assertSame(self::OWNER_TELEGRAM_ID, $link->telegram_user_id);
        $this->assertTrue($link->is_active);
    }

    public function test_owner_can_register_webhook_and_fetch_webhook_info(): void
    {
        $owner = User::factory()->create();

        Http::fake([
            'https://api.telegram.org/bot'.self::BOT_TOKEN.'/setWebhook' => Http::response([
                'ok' => true,
                'result' => true,
                'description' => 'Webhook was set',
            ], 200),
            'https://api.telegram.org/bot'.self::BOT_TOKEN.'/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => 'https://financeassistant.test/api/v1/telegram/webhook',
                    'has_custom_certificate' => false,
                    'pending_update_count' => 0,
                    'allowed_updates' => ['message', 'widget_query'],
                ],
            ], 200),
        ]);

        // Register webhook
        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/telegram/register-webhook')
            ->assertOk()
            ->assertJsonPath('result.ok', true);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bot'.self::BOT_TOKEN.'/setWebhook'
                && $request['url'] === 'https://financeassistant.test/api/v1/telegram/webhook'
                && $request['secret_token'] === self::WEBHOOK_SECRET
                && $request['allowed_updates'] === ['message', 'widget_query'];
        });

        // Webhook info
        $infoRes = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/telegram/webhook-info')
            ->assertOk();

        $this->assertSame('https://financeassistant.test/api/v1/telegram/webhook', $infoRes->json('data.url'));
        $this->assertSame(['message', 'widget_query'], $infoRes->json('data.allowed_updates'));
    }

    public function test_telegram_status_endpoint_inspects_and_auto_registers_webhook_and_links_owner(): void
    {
        $owner = User::factory()->create();

        Http::fake([
            'https://api.telegram.org/bot'.self::BOT_TOKEN.'/getWebhookInfo' => Http::sequence()
                ->push([
                    'ok' => true,
                    'result' => [
                        'url' => '',
                        'pending_update_count' => 0,
                    ],
                ], 200)
                ->push([
                    'ok' => true,
                    'result' => [
                        'url' => 'https://financeassistant.test/api/v1/telegram/webhook',
                        'pending_update_count' => 0,
                    ],
                ], 200),
            'https://api.telegram.org/bot'.self::BOT_TOKEN.'/setWebhook' => Http::response([
                'ok' => true,
                'result' => true,
                'description' => 'Webhook was set',
            ], 200),
        ]);

        $res = $this->getJson('/api/v1/telegram/status')->assertOk();

        $this->assertTrue($res->json('bot_token_configured'));
        $this->assertTrue($res->json('owner_id_configured'));
        $this->assertTrue($res->json('webhook_secret_configured'));
        $this->assertTrue($res->json('owner_linked'));
        $this->assertTrue($res->json('auto_registered'));
        $this->assertSame('https://financeassistant.test/api/v1/telegram/webhook', $res->json('webhook_info.url'));

        $this->assertSame(1, TelegramOwnerLink::query()->where('owner_id', $owner->id)->count());
    }
}

