<?php

namespace Tests\Feature;

use App\Domain\Notifications\InMemoryNotificationTransport;
use App\Domain\Notifications\NotificationCategory;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationDeliveryStatus;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTransportInterface;
use App\Domain\Telegram\TelegramOwnerLinkService;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferences_are_owner_scoped_and_default_to_enabled(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service = app(NotificationService::class);

        $this->assertTrue($service->isEnabled($owner, NotificationCategory::BudgetThreshold));
        $this->assertTrue($service->isEnabled($owner, NotificationCategory::SyncFailure));

        $disabled = $service->setPreference($owner, NotificationCategory::BudgetThreshold, false);

        $this->assertFalse($disabled->is_enabled);
        $this->assertFalse($service->isEnabled($owner, NotificationCategory::BudgetThreshold));
        $this->assertTrue($service->isEnabled($other, NotificationCategory::BudgetThreshold));

        $reenabled = $service->setPreference($owner, NotificationCategory::BudgetThreshold, true);

        $this->assertSame($disabled->id, $reenabled->id);
        $this->assertTrue($service->isEnabled($owner, NotificationCategory::BudgetThreshold));
        $this->assertSame(1, NotificationPreference::query()->where('owner_id', $owner->id)->count());
    }

    public function test_deduplicates_delivery_by_owner_and_deduplication_key(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        /** @var InMemoryNotificationTransport $transport */
        $transport = app(NotificationTransportInterface::class);
        $service = app(NotificationService::class);

        $first = $service->dispatch(
            $owner,
            NotificationCategory::BudgetThreshold,
            'budget-threshold:2026-03-11',
            'Daily spending limit reached for AMD.',
            ['currency' => 'AMD', 'planning_days' => 30],
        );

        $duplicate = $service->dispatch(
            $owner,
            NotificationCategory::BudgetThreshold,
            '  budget-threshold:2026-03-11  ',
            'Daily spending limit reached for AMD.',
            ['currency' => 'AMD', 'planning_days' => 30],
        );

        $otherOwnerDispatch = $service->dispatch(
            $other,
            NotificationCategory::BudgetThreshold,
            'budget-threshold:2026-03-11',
            'Other owner alert.',
        );

        $this->assertFalse($first->wasDuplicate);
        $this->assertTrue($first->wasDelivered);
        $this->assertSame(NotificationDeliveryStatus::Sent, $first->notification->status);
        $this->assertNotNull($first->notification->delivered_at);

        $this->assertTrue($duplicate->wasDuplicate);
        $this->assertFalse($duplicate->wasDelivered);
        $this->assertSame($first->notification->id, $duplicate->notification->id);

        $this->assertFalse($otherOwnerDispatch->wasDuplicate);
        $this->assertTrue($otherOwnerDispatch->wasDelivered);

        $this->assertCount(2, $transport->deliveries());
        $this->assertSame(1, Notification::query()->where('owner_id', $owner->id)->count());
        $this->assertSame(1, Notification::query()->where('owner_id', $other->id)->count());

        $rawDuplicate = $first->notification->replicate();
        $this->expectException(QueryException::class);
        $rawDuplicate->save();
    }

    public function test_disabled_preference_suppresses_delivery_and_remains_idempotent(): void
    {
        $owner = User::factory()->create();
        /** @var InMemoryNotificationTransport $transport */
        $transport = app(NotificationTransportInterface::class);
        $service = app(NotificationService::class);

        $service->setPreference($owner, NotificationCategory::DailySummary, false);

        $suppressed = $service->dispatch(
            $owner,
            NotificationCategory::DailySummary,
            'daily-summary:2026-03-11',
            'Daily summary for 2026-03-11.',
        );

        $repeat = $service->dispatch(
            $owner,
            NotificationCategory::DailySummary,
            'daily-summary:2026-03-11',
            'Daily summary for 2026-03-11.',
        );

        $this->assertFalse($suppressed->wasDuplicate);
        $this->assertFalse($suppressed->wasDelivered);
        $this->assertSame(NotificationDeliveryStatus::Suppressed, $suppressed->notification->status);
        $this->assertNull($suppressed->notification->delivered_at);

        $this->assertTrue($repeat->wasDuplicate);
        $this->assertFalse($repeat->wasDelivered);
        $this->assertCount(0, $transport->deliveries());
        $this->assertSame(1, Notification::query()->where('owner_id', $owner->id)->count());
    }

    public function test_rejects_sensitive_payloads_and_invalid_keys_without_persisting_or_sending(): void
    {
        $owner = User::factory()->create();
        /** @var InMemoryNotificationTransport $transport */
        $transport = app(NotificationTransportInterface::class);
        $service = app(NotificationService::class);

        foreach ([
            ['', 'Valid message', []],
            [str_repeat('k', 192), 'Valid message', []],
            ['valid-key', '   ', []],
            ['valid-key', 'Valid message', ['access_token' => 'secret']],
            ['valid-key', 'Valid message', ['nested' => ['card_number' => '4111111111111111']]],
            ['valid-key', 'Valid message', ['bank_password' => 'hunter2']],
            ['valid-key', 'Valid message', ['raw_payload' => '<xml>raw</xml>']],
        ] as [$key, $message, $payload]) {
            try {
                $service->dispatch(
                    $owner,
                    NotificationCategory::SyncFailure,
                    $key,
                    $message,
                    $payload,
                    NotificationChannel::Telegram,
                );
                $this->fail('Invalid or sensitive notification dispatch must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, Notification::query()->count());
                $this->assertCount(0, $transport->deliveries());
            }
        }
    }

    public function test_telegram_notifications_command_lists_and_updates_owner_preferences(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.webhook_secret' => 'webhook-secret',
            'services.telegram.owner_id' => 555666777,
        ]);

        $owner = User::factory()->create();
        app(TelegramOwnerLinkService::class)->linkVerifiedOwner($owner, 555666777);

        $toggleResponse = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'webhook-secret')
            ->postJson('/api/v1/telegram/webhook', [
                'update_id' => 901,
                'message' => [
                    'message_id' => 1,
                    'from' => ['id' => 555666777, 'is_bot' => false],
                    'chat' => ['id' => 555666777, 'type' => 'private'],
                    'text' => '/notifications BUDGET_THRESHOLD off',
                ],
            ])
            ->assertOk();

        $this->assertSame(
            'Notification preference updated: BUDGET_THRESHOLD is now OFF.',
            $toggleResponse->json('reply_text'),
        );

        $listResponse = $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'webhook-secret')
            ->postJson('/api/v1/telegram/webhook', [
                'update_id' => 902,
                'message' => [
                    'message_id' => 2,
                    'from' => ['id' => 555666777, 'is_bot' => false],
                    'chat' => ['id' => 555666777, 'type' => 'private'],
                    'text' => '/notifications',
                ],
            ])
            ->assertOk();

        $this->assertStringContainsString('- BUDGET_THRESHOLD: OFF', (string) $listResponse->json('reply_text'));
        $this->assertStringContainsString('- SYNC_FAILURE: ON', (string) $listResponse->json('reply_text'));
    }
}
