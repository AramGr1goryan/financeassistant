<?php

namespace App\Domain\Telegram;

use App\Models\TelegramOwnerLink;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TelegramOwnerLinkService
{
    public function configuredOwnerTelegramId(): ?int
    {
        $raw = config('services.telegram.owner_id');

        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        if (is_string($raw) && preg_match('/\A[1-9]\d{0,17}\z/D', $raw) === 1) {
            $value = (int) $raw;

            return $value > 0 ? $value : null;
        }

        return null;
    }

    public function linkVerifiedOwner(User $owner, mixed $telegramUserId, mixed $telegramChatId = null): TelegramOwnerLink
    {
        $chatId = $telegramChatId ?? $telegramUserId;

        if (! is_int($telegramUserId) || $telegramUserId <= 0 || ! is_int($chatId) || $chatId <= 0) {
            throw new InvalidArgumentException('Telegram user ID and chat ID must be positive integers.');
        }

        if ($telegramUserId !== $chatId) {
            throw new InvalidArgumentException('Telegram owner link requires a private chat matching the Telegram user ID.');
        }

        $configuredOwnerId = $this->configuredOwnerTelegramId();

        if ($configuredOwnerId === null || $telegramUserId !== $configuredOwnerId) {
            throw new TelegramAuthorizationException('Telegram identity is not pre-authorized for owner linking.');
        }

        return DB::transaction(function () use ($owner, $telegramUserId, $chatId): TelegramOwnerLink {
            $lockedOwner = User::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

            $conflictingLink = TelegramOwnerLink::query()
                ->where('telegram_user_id', $telegramUserId)
                ->where('owner_id', '!=', $lockedOwner->getKey())
                ->lockForUpdate()
                ->first();

            if ($conflictingLink !== null) {
                throw new TelegramAuthorizationException('Telegram identity is already linked to another owner.');
            }

            $link = TelegramOwnerLink::query()
                ->where('owner_id', $lockedOwner->getKey())
                ->lockForUpdate()
                ->first();

            if ($link === null) {
                $link = new TelegramOwnerLink();
                $link->owner()->associate($lockedOwner);
            }

            $link->fill([
                'telegram_user_id' => $telegramUserId,
                'telegram_chat_id' => $chatId,
                'is_active' => true,
                'verified_at' => Carbon::now('UTC'),
            ]);
            $link->save();

            return $link;
        });
    }

    public function resolveAuthorizedOwner(int $telegramUserId, int $telegramChatId, string $chatType): User
    {
        $configuredOwnerId = $this->configuredOwnerTelegramId();

        if ($configuredOwnerId === null
            || $chatType !== 'private'
            || $telegramUserId <= 0
            || $telegramChatId <= 0
            || $telegramUserId !== $telegramChatId
            || $telegramUserId !== $configuredOwnerId) {
            throw new TelegramAuthorizationException('Unauthorized Telegram identity.');
        }

        $link = TelegramOwnerLink::query()
            ->where('telegram_user_id', $configuredOwnerId)
            ->where('telegram_chat_id', $configuredOwnerId)
            ->where('is_active', true)
            ->whereNotNull('verified_at')
            ->with('owner')
            ->first();

        if ($link === null || $link->owner === null) {
            throw new TelegramAuthorizationException('Telegram identity is not linked to an authorized owner.');
        }

        return $link->owner;
    }
}

