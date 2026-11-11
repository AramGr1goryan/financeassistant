<?php

namespace App\Domain\Telegram;

use RuntimeException;

final class TelegramDeliveryException extends RuntimeException
{
    public function __construct(public readonly ?int $httpStatus = null, ?string $detail = null)
    {
        parent::__construct($detail ?? 'Telegram did not accept the reply.');
    }
}
