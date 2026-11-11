<?php

namespace App\Domain\Telegram;

use App\Models\User;

final class TelegramAuthService
{
    public function __construct(
        private readonly TelegramOwnerLinkService $ownerLinks,
    ) {}

    public function authenticateInitData(string $initDataString): User
    {
        $botToken = config('services.telegram.bot_token');

        if (! is_string($botToken) || trim($botToken) === '') {
            throw new TelegramAuthorizationException('Telegram bot token is not configured.');
        }

        parse_str($initDataString, $params);

        if (! isset($params['hash']) || ! is_string($params['hash']) || $params['hash'] === '') {
            throw new TelegramAuthorizationException('Missing Telegram initData hash.');
        }

        $hash = $params['hash'];
        unset($params['hash']);

        $authDate = isset($params['auth_date']) ? (int) $params['auth_date'] : 0;
        $now = time();

        // Must be within 86400 seconds (24h) and not more than 300s in the future
        if ($authDate <= 0 || ($now - $authDate) > 86400 || ($authDate - $now) > 300) {
            throw new TelegramAuthorizationException('Telegram initData has expired or is invalid.');
        }

        ksort($params);
        $dataCheckArr = [];
        foreach ($params as $key => $value) {
            $dataCheckArr[] = "{$key}={$value}";
        }
        $dataCheckString = implode("\n", $dataCheckArr);

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($calculatedHash, $hash)) {
            throw new TelegramAuthorizationException('Invalid Telegram initData signature.');
        }

        if (! isset($params['user']) || ! is_string($params['user'])) {
            throw new TelegramAuthorizationException('Missing user object in Telegram initData.');
        }

        $userData = json_decode($params['user'], true);

        if (! is_array($userData) || ! isset($userData['id']) || ! is_int($userData['id']) || $userData['id'] <= 0) {
            throw new TelegramAuthorizationException('Invalid user object in Telegram initData.');
        }

        $telegramUserId = $userData['id'];

        return $this->ownerLinks->resolveAuthorizedOwner($telegramUserId, $telegramUserId, 'private');
    }
}
