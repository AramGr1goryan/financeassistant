<?php

namespace App\Http\Controllers\Api;

use App\Domain\Income\IncomeExpectationService;
use App\Domain\Money\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateIncomeExpectationRequest;
use App\Http\Resources\MoneyResource;
use App\Models\IncomeExpectation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class IncomeExpectationController extends Controller
{
    public function index(Request $request, IncomeExpectationService $expectations): JsonResponse
    {
        $items = $expectations->forOwner($request->user())->map(fn (IncomeExpectation $expectation): array => $this->resource($expectation));

        return response()->json(['data' => $items])->header('Cache-Control', 'no-store');
    }

    public function store(CreateIncomeExpectationRequest $request, IncomeExpectationService $expectations): JsonResponse
    {
        $data = $request->validated();
        $amount = new Money((int) $data['amount_minor_units'], 'AMD', 0);

        try {
            [$expectation, $created] = DB::transaction(function () use ($request, $data, $amount, $expectations): array {
                $owner = User::query()->whereKey($request->user()->getAuthIdentifier())->lockForUpdate()->firstOrFail();
                $existing = IncomeExpectation::query()->whereKey($data['id'])->first();

                if ($existing !== null) {
                    abort_unless(
                        (string) $existing->owner_id === (string) $owner->getKey()
                            && $existing->description === trim($data['description'])
                            && $existing->amount_minor_units === $amount->minorUnits
                            && $existing->currency === 'AMD'
                            && $existing->currency_exponent === 0
                            && $existing->expected_date === $data['expected_date'],
                        409,
                        'Expectation identifier is already in use.',
                    );

                    return [$existing, false];
                }

                return [$expectations->create(
                    $owner,
                    $data['description'],
                    $amount,
                    $data['expected_date'],
                    $data['id'],
                ), true];
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['expected_date' => $exception->getMessage()]);
        }

        return response()->json([
            'data' => $this->resource($expectation),
            'was_created' => $created,
        ], $created ? 201 : 200)->header('Cache-Control', 'no-store');
    }

    public function complete(Request $request, string $id, IncomeExpectationService $expectations): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $expectation = $expectations->complete($request->user(), $id);

        return response()->json(['data' => $this->resource($expectation)])->header('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(IncomeExpectation $expectation): array
    {
        return [
            'id' => $expectation->id,
            'description' => $expectation->description,
            'amount' => new MoneyResource(new Money(
                $expectation->amount_minor_units,
                $expectation->currency,
                $expectation->currency_exponent,
            )),
            'expected_date' => $expectation->expected_date,
            'status' => $expectation->status->value,
        ];
    }
}
