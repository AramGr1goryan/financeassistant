<?php

namespace App\Domain\Notifications;

use App\Models\Notification;

final readonly class NotificationDispatchResult
{
    public function __construct(
        public Notification $notification,
        public bool $wasDuplicate,
        public bool $wasDelivered,
    ) {}
}

