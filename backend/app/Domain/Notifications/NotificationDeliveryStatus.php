<?php

namespace App\Domain\Notifications;

enum NotificationDeliveryStatus: string
{
    case Pending = 'PENDING';
    case Sent = 'SENT';
    case Suppressed = 'SUPPRESSED';
    case Failed = 'FAILED';
}

