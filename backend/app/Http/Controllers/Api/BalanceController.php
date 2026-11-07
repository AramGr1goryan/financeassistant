<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accounts\BalanceCalculator;
use App\Domain\Accounts\CurrencyExponentMismatchException;
use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialCurrencyRequest;
use App\Http\Resources\MoneyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class BalanceController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(FinancialCurrencyRequest $request, BalanceCalculator $balances): JsonResponse
    {
        try {
            $balance = $balances->calculateForOwner(
                $request->user()->getAuthIdentifier(),
                $request->currency(),
                $request->currencyExponent(),
            );
        } catch (CurrencyExponentMismatchException $exception) {
            throw ValidationException::withMessages(['currency_exponent' => $exception->getMessage()]);
        }

        return response()->json(['data' => [
            'owned_funds' => new MoneyResource($balance->ownedFunds),
            'pending_owned_funds_change' => new MoneyResource($balance->pendingOwnedFundsChange),
            'pending_owned_funds_debits' => new MoneyResource($balance->pendingOwnedFundsDebits),
            'credit_liabilities' => new MoneyResource($balance->creditLiabilities),
            'pending_credit_liability_change' => new MoneyResource($balance->pendingCreditLiabilityChange),
        ]])->header('Cache-Control', 'no-store');
    }
}
