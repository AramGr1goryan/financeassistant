<?php

namespace App\Console\Commands;

use App\Domain\Telegram\TelegramOwnerLinkService;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('finance:link-telegram-owner {email? : Email of the workspace user}')]
#[Description('Link a workspace user to the configured Telegram owner ID')]
class LinkTelegramOwner extends Command
{
    public function handle(TelegramOwnerLinkService $linkService): int
    {
        $configuredId = $linkService->configuredOwnerTelegramId();

        if ($configuredId === null) {
            $this->error('TELEGRAM_OWNER_ID is not configured in environment.');

            return self::FAILURE;
        }

        $email = $this->argument('email');

        if (is_string($email) && trim($email) !== '') {
            $owner = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->first();
        } else {
            $owner = User::query()->orderBy('id')->first();
        }

        if ($owner === null) {
            $this->error('No workspace user found to link.');

            return self::FAILURE;
        }

        try {
            $link = $linkService->linkVerifiedOwner($owner, $configuredId);
        } catch (\Throwable $e) {
            $this->error('Linking failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Linked user '{$owner->email}' to configured Telegram owner ID {$link->telegram_user_id}.");

        return self::SUCCESS;
    }
}

