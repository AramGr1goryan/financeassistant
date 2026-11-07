<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accounts\BudgetPlanningService;
use App\Domain\Money\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveBudgetSettingRequest;
use Illuminate\Http\Response;

class BudgetSettingController extends Controller
{
    public function __invoke(SaveBudgetSettingRequest $request, BudgetPlanningService $budgets): Response
    {
        $data = $request->validated();
        $budgets->save(
            $request->user(),
            Money::fromDecimalString($data['mandatory_payments'], 'AMD', 0),
            Money::fromDecimalString($data['protected_reserve'], 'AMD', 0),
            Money::fromDecimalString($data['allocated_savings'], 'AMD', 0),
            (int) $data['planning_days'],
        );

        return response()->noContent()->header('Cache-Control', 'no-store');
    }
}
