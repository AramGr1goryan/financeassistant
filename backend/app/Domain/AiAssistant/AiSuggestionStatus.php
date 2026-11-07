<?php

namespace App\Domain\AiAssistant;

enum AiSuggestionStatus: string
{
    case PendingReview = 'PENDING_REVIEW';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
}

