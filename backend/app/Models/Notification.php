<?php

namespace App\Models;

use App\Domain\Notifications\NotificationCategory;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationDeliveryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use HasUuids;

    protected $fillable = [
        'channel',
        'category',
        'deduplication_key',
        'message',
        'payload',
        'status',
        'delivered_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'category' => NotificationCategory::class,
            'payload' => 'array',
            'status' => NotificationDeliveryStatus::class,
            'delivered_at' => 'immutable_datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}

