<?php

namespace App\Models;

use App\Domain\AiAssistant\AiSuggestionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCategorySuggestion extends Model
{
    use HasUuids;

    protected $fillable = [
        'transaction_id',
        'suggested_category_id',
        'confidence_percent',
        'status',
        'rationale',
        'reviewed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence_percent' => 'integer',
            'status' => AiSuggestionStatus::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function suggestedCategory(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'suggested_category_id');
    }
}

