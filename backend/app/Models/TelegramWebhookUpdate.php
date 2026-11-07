<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramWebhookUpdate extends Model
{
    use HasUuids;

    protected $fillable = [
        'update_id',
        'telegram_user_id',
        'telegram_chat_id',
        'command',
        'response_text',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'update_id' => 'integer',
            'telegram_user_id' => 'integer',
            'telegram_chat_id' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}

