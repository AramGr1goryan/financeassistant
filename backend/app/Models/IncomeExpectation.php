<?php

namespace App\Models;

use App\Domain\Income\IncomeExpectationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomeExpectation extends Model
{
    use HasUuids;

    protected $fillable = [
        'description',
        'amount_minor_units',
        'currency',
        'currency_exponent',
        'expected_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor_units' => 'integer',
            'currency_exponent' => 'integer',
            'status' => IncomeExpectationStatus::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
