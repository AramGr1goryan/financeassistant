<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accounts\BudgetPlanningService;
use App\Domain\Accounts\CurrencyExponentMismatchException;
use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialCurrencyRequest;
use App\Http\Resources\MoneyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class BudgetController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(FinancialCurrencyRequest $request, BudgetPlanningService $budgets): JsonResponse
    {
        try {
            $budget = $budgets->calculate(
                $request->user(),
                $request->currency(),
                $request->currencyExponent(),
            );
        } catch (CurrencyExponentMismatchException $exception) {
            throw ValidationException::withMessages(['currency_exponent' => $exception->getMessage()]);
        }

        return response()->json(['data' => [
            'available_funds' => new MoneyResource($budget->availableFunds),
            'daily_limit' => new MoneyResource($budget->dailyLimit),
            'mandatory_payments' => new MoneyResource($budget->mandatoryPayments),
            'protected_reserve' => new MoneyResource($budget->protectedReserve),
            'allocated_savings' => new MoneyResource($budget->allocatedSavings),
            'planning_days' => $budget->planningDays,
        ]])->header('Cache-Control', 'no-store');
    }
}
