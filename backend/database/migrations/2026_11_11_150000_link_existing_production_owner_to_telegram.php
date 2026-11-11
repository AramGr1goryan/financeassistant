<?php

use App\Domain\Telegram\TelegramOwnerLinkService;
use App\Models\TelegramOwnerLink;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $configuredId = app(TelegramOwnerLinkService::class)->configuredOwnerTelegramId();

        if ($configuredId !== null && TelegramOwnerLink::query()->count() === 0) {
            $owner = User::query()->orderBy('id')->first();

            if ($owner !== null) {
                $now = Carbon::now('UTC');
                $link = new TelegramOwnerLink([
                    'telegram_user_id' => $configuredId,
                    'telegram_chat_id' => $configuredId,
                    'is_active' => true,
                    'verified_at' => $now,
                ]);
                $link->id = (string) Str::uuid();
                $link->owner()->associate($owner);
                $link->save();
            }
        }
    }

    public function down(): void
    {
        // Safe no-op to prevent inadvertent unlinking on rollback
    }
};
