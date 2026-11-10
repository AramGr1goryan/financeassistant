<?php

namespace App\Domain\Income;

use App\Domain\FinancialCalendar;
use App\Domain\Money\Money;
use App\Models\IncomeExpectation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class IncomeExpectationService
{
    public function __construct(private readonly FinancialCalendar $calendar) {}

    public function create(User $owner, string $description, Money $amount, string $expectedDate, ?string $id = null): IncomeExpectation
    {
        $description = trim($description);

        if ($description === '' || mb_strlen($description) > 255) {
            throw new InvalidArgumentException('Description must contain between 1 and 255 characters.');
        }

        if ($amount->minorUnits <= 0) {
            throw new InvalidArgumentException('Expected income must be positive.');
        }

        $this->calendar->utcBoundsForFinancialDay($expectedDate);

        if (str_starts_with($expectedDate, '0000-')) {
            throw new InvalidArgumentException('Financial date year must be at least 0001.');
        }

        $expectation = new IncomeExpectation([
            'description' => $description,
            'amount_minor_units' => $amount->minorUnits,
            'currency' => $amount->currency,
            'currency_exponent' => $amount->currencyExponent,
            'expected_date' => $expectedDate,
            'status' => IncomeExpectationStatus::Planned,
        ]);
        if ($id !== null) {
            $expectation->id = $id;
        }
        $expectation->owner()->associate($owner);
        $expectation->save();

        return $expectation;
    }

    /** @return Collection<int, IncomeExpectation> */
    public function forOwner(User $owner): Collection
    {
        return IncomeExpectation::query()
            ->where('owner_id', $owner->getKey())
            ->orderBy('expected_date')
            ->orderBy('id')
            ->get();
    }

    public function complete(User $owner, string $id): IncomeExpectation
    {
        return DB::transaction(function () use ($owner, $id): IncomeExpectation {
            $expectation = IncomeExpectation::query()
                ->where('owner_id', $owner->getKey())
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($expectation->status === IncomeExpectationStatus::Planned) {
                $expectation->status = IncomeExpectationStatus::Completed;
                $expectation->save();
            }

            return $expectation;
        });
    }
}
