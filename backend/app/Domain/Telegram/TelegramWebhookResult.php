<?php

namespace App\Domain\Telegram;

final readonly class TelegramWebhookResult
{
    public function __construct(
        public bool $duplicate,
        public int $updateId,
        public int $chatId,
        public ?string $command,
        public string $replyText,
    ) {}
}

