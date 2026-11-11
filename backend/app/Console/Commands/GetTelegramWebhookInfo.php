<?php

namespace App\Console\Commands;

use App\Domain\Telegram\TelegramBotClient;
use App\Domain\Telegram\TelegramDeliveryException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:webhook-info')]
#[Description('Fetch current webhook registration status from Telegram Bot API')]
class GetTelegramWebhookInfo extends Command
{
    public function handle(TelegramBotClient $botClient): int
    {
        try {
            $info = $botClient->getWebhookInfo();
        } catch (TelegramDeliveryException $exception) {
            $this->error('Failed to get webhook info. HTTP status: '.$exception->httpStatus);

            return self::FAILURE;
        }

        $this->info('Telegram Webhook Information:');
        $this->line('- URL: '.($info['url'] ?? 'Not set'));
        $this->line('- Has custom certificate: '.(($info['has_custom_certificate'] ?? false) ? 'yes' : 'no'));
        $this->line('- Pending update count: '.($info['pending_update_count'] ?? 0));
        $this->line('- Max connections: '.($info['max_connections'] ?? 'default'));
        $this->line('- Allowed updates: '.implode(', ', (array) ($info['allowed_updates'] ?? [])));

        if (! empty($info['last_error_date'])) {
            $this->warn('- Last error date: '.date('Y-m-d H:i:s', $info['last_error_date']));
            $this->warn('- Last error message: '.($info['last_error_message'] ?? ''));
        }

        return self::SUCCESS;
    }
}

