<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetSetting extends Model
{
    use HasUuids;

    protected $fillable = [
        'currency',
        'currency_exponent',
        'revision',
        'planning_days',
        'mandatory_payments_minor_units',
        'protected_reserve_minor_units',
        'allocated_savings_minor_units',
    ];

    protected function casts(): array
    {
        return [
            'currency_exponent' => 'integer',
            'revision' => 'integer',
            'planning_days' => 'integer',
            'mandatory_payments_minor_units' => 'integer',
            'protected_reserve_minor_units' => 'integer',
            'allocated_savings_minor_units' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
