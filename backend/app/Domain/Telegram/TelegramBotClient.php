<?php

namespace App\Domain\Telegram;

use Illuminate\Support\Facades\Http;

final class TelegramBotClient
{
    public function sendMessage(int $chatId, string $text): void
    {
        $token = config('services.telegram.bot_token');

        if (! is_string($token) || trim($token) === '') {
            throw new TelegramDeliveryException;
        }

        $response = Http::acceptJson()
            ->asJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
            ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new TelegramDeliveryException($response->status());
        }
    }
}
