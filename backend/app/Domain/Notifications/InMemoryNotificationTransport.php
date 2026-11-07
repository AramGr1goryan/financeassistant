<?php

namespace App\Domain\Notifications;

use App\Models\User;

final class InMemoryNotificationTransport implements NotificationTransportInterface
{
    /**
     * @var list<array{owner_id: int|string, channel: NotificationChannel, message: string}>
     */
    private array $deliveries = [];

    public function send(User $owner, NotificationChannel $channel, string $message): void
    {
        $this->deliveries[] = [
            'owner_id' => $owner->getKey(),
            'channel' => $channel,
            'message' => $message,
        ];
    }

    /**
     * @return list<array{owner_id: int|string, channel: NotificationChannel, message: string}>
     */
    public function deliveries(): array
    {
        return $this->deliveries;
    }
}

