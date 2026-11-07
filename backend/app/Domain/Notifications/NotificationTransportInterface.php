<?php

namespace App\Domain\Notifications;

use App\Models\User;

interface NotificationTransportInterface
{
    public function send(User $owner, NotificationChannel $channel, string $message): void;
}

