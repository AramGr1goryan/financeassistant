<?php

namespace App\Models;

use App\Domain\Transactions\TransactionStatus;
use App\Domain\Transactions\TransactionType;
use App\Domain\Transactions\TransferDirection;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'account_id',
        'amount_minor_units',
        'currency',
        'currency_exponent',
        'type',
        'status',
        'transaction_at',
        'posting_at',
        'description',
        'merchant',
        'external_bank_transaction_id',
        'idempotency_key',
        'category_id',
        'original_transaction_id',
        'transfer_group_id',
        'transfer_reference',
        'transfer_direction',
        'is_generated_counterpart',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor_units' => 'integer',
            'currency_exponent' => 'integer',
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'transfer_direction' => TransferDirection::class,
            'is_generated_counterpart' => 'boolean',
            'transaction_at' => 'immutable_datetime',
            'posting_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'category_id');
    }

    public function originalTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_transaction_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(self::class, 'original_transaction_id');
    }

    public function transferCounterparts(): HasMany
    {
        return $this->hasMany(self::class, 'transfer_group_id', 'transfer_group_id')
            ->where('id', '!=', $this->getKey());
    }
}
