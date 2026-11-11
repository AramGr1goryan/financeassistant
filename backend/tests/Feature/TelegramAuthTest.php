<?php

namespace Tests\Feature;

use App\Domain\Telegram\TelegramOwnerLinkService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramAuthTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '123456789:ABCdefGHIjklMNOpqrsTUVwxyz';
    private const OWNER_TELEGRAM_ID = 987654321;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram.bot_token' => self::BOT_TOKEN,
            'services.telegram.owner_id' => self::OWNER_TELEGRAM_ID,
        ]);
    }

    public function test_valid_signed_init_data_authenticates_linked_owner(): void
    {
        $owner = User::factory()->create();
        app(TelegramOwnerLinkService::class)->linkVerifiedOwner($owner, self::OWNER_TELEGRAM_ID);

        $initData = $this->generateValidInitData(self::OWNER_TELEGRAM_ID, time());

        $response = $this->postJson('/api/v1/telegram/auth', [
            'init_data' => $initData,
        ])->assertOk();

        $response->assertJson([
            'authenticated' => true,
            'user' => [
                'id' => $owner->id,
                'email' => $owner->email,
            ],
        ]);

        $this->assertAuthenticatedAs($owner, 'web');
    }

    public function test_forged_init_data_signature_is_rejected(): void
    {
        $owner = User::factory()->create();
        app(TelegramOwnerLinkService::class)->linkVerifiedOwner($owner, self::OWNER_TELEGRAM_ID);

        $initData = $this->generateValidInitData(self::OWNER_TELEGRAM_ID, time());
        $tampered = $initData.'&extra=tampered';

        $this->postJson('/api/v1/telegram/auth', [
            'init_data' => $tampered,
        ])->assertStatus(401);

        $this->assertGuest('web');
    }

    public function test_expired_init_data_is_rejected(): void
    {
        $owner = User::factory()->create();
        app(TelegramOwnerLinkService::class)->linkVerifiedOwner($owner, self::OWNER_TELEGRAM_ID);

        // 2 days ago
        $expiredTime = time() - 172800;
        $initData = $this->generateValidInitData(self::OWNER_TELEGRAM_ID, $expiredTime);

        $this->postJson('/api/v1/telegram/auth', [
            'init_data' => $initData,
        ])->assertStatus(401);

        $this->assertGuest('web');
    }

    public function test_unlinked_telegram_user_is_rejected(): void
    {
        $unlinkedTelegramId = 555444333;
        $initData = $this->generateValidInitData($unlinkedTelegramId, time());

        $this->postJson('/api/v1/telegram/auth', [
            'init_data' => $initData,
        ])->assertStatus(401);

        $this->assertGuest('web');
    }

    private function generateValidInitData(int $telegramUserId, int $authDate): string
    {
        $params = [
            'auth_date' => (string) $authDate,
            'query_id' => 'AAHD8_8AAAAAM',
            'user' => json_encode([
                'id' => $telegramUserId,
                'first_name' => 'Owner',
                'username' => 'finance_owner',
            ]),
        ];

        ksort($params);
        $dataCheckArr = [];
        foreach ($params as $k => $v) {
            $dataCheckArr[] = "{$k}={$v}";
        }
        $dataCheckString = implode("\n", $dataCheckArr);

        $secretKey = hash_hmac('sha256', self::BOT_TOKEN, 'WebAppData', true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);

        $params['hash'] = $hash;

        return http_build_query($params);
    }
}
