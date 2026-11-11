<?php

namespace App\Console\Commands;

use App\Domain\Telegram\TelegramBotClient;
use App\Domain\Telegram\TelegramDeliveryException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:register-webhook {--url= : Override webhook URL} {--secret= : Override secret token}')]
#[Description('Register the Telegram bot webhook with allowed updates including widget_query')]
class RegisterTelegramWebhook extends Command
{
    public function handle(TelegramBotClient $botClient): int
    {
        $url = $this->option('url');
        $secret = $this->option('secret');

        try {
            $response = $botClient->registerWebhook(
                is_string($url) && $url !== '' ? $url : null,
                is_string($secret) && $secret !== '' ? $secret : null,
            );
        } catch (TelegramDeliveryException $exception) {
            $this->error('Failed to register Telegram webhook. HTTP status: '.$exception->httpStatus);

            return self::FAILURE;
        }

        $targetUrl = is_string($url) && $url !== '' ? $url : (rtrim((string) config('app.url'), '/').'/api/v1/telegram/webhook');
        $this->info("Telegram webhook registered successfully for: {$targetUrl}");
        $this->line('Allowed updates: message, widget_query');

        return self::SUCCESS;
    }
}

