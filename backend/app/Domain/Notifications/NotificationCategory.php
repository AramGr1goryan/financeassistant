<?php

namespace App\Domain\Notifications;

enum NotificationCategory: string
{
    case BudgetThreshold = 'BUDGET_THRESHOLD';
    case SyncFailure = 'SYNC_FAILURE';
    case LargeOutflow = 'LARGE_OUTFLOW';
    case DailySummary = 'DAILY_SUMMARY';
}

