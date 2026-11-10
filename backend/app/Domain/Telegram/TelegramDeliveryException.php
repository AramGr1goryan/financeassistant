<?php

namespace App\Domain\Telegram;

use RuntimeException;

final class TelegramDeliveryException extends RuntimeException
{
    public function __construct(public readonly ?int $httpStatus = null)
    {
        parent::__construct('Telegram did not accept the reply.');
    }
}
