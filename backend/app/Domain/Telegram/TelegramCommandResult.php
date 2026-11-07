<?php

namespace App\Domain\Telegram;

final readonly class TelegramCommandResult
{
    public function __construct(
        public ?string $command,
        public string $replyText,
    ) {}
}

