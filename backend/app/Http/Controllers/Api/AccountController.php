<?php

namespace App\Http\Controllers\Api;

use App\Domain\Accounts\AccountType;
use App\Domain\Accounts\BalanceCalculator;
use App\Domain\Accounts\CurrencyExponentMismatchException;
use App\Domain\Money\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateAccountRequest;
use App\Http\Resources\MoneyResource;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function index(Request $request, BalanceCalculator $balances): JsonResponse
    {
        try {
            $sheet = $balances->calculateForOwner($request->user()->getAuthIdentifier(), 'AMD', 0);
        } catch (CurrencyExponentMismatchException $exception) {
            throw ValidationException::withMessages(['currency_exponent' => $exception->getMessage()]);
        }

        $accounts = Account::query()->where('owner_id', $request->user()->getAuthIdentifier())
            ->where('currency', 'AMD')->get()->keyBy('id');

        $items = array_map(function ($balance) use ($accounts): array {
            $account = $accounts->get($balance->accountId);
            if ($account === null) {
                throw new \LogicException('Account disappeared while calculating balances.');
            }

            return [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'is_active' => $account->is_active,
                'posted_balance' => new MoneyResource($balance->postedBalance),
                'pending_change' => new MoneyResource($account->type === AccountType::Credit ? $balance->pendingCreditLiabilityChange : $balance->pendingChange),
                'credit_liability' => new MoneyResource($balance->creditLiability),
            ];
        }, $sheet->accounts);

        return response()->json(['data' => $items])->header('Cache-Control', 'no-store');
    }

    public function store(CreateAccountRequest $request): JsonResponse
    {
        $data = $request->validated();
        $opening = Money::fromDecimalString($data['opening_balance'], 'AMD', 0);

        $account = DB::transaction(function () use ($request, $data, $opening): Account {
            $owner = User::query()->whereKey($request->user()->getAuthIdentifier())->lockForUpdate()->firstOrFail();
            $account = Account::query()->whereKey($data['id'])->first();
            if ($account !== null) {
                abort_unless(
                    (string) $account->owner_id === (string) $owner->getKey()
                    && $account->name === $data['name']
                    && $account->type->value === $data['type']
                    && $account->currency === 'AMD'
                    && $account->currency_exponent === 0
                    && $account->opening_balance_minor_units === $opening->minorUnits,
                    409,
                    'Account identifier is already in use.',
                );

                return $account;
            }

            $account = new Account([
                'name' => $data['name'],
                'type' => $data['type'],
                'currency' => 'AMD',
                'currency_exponent' => 0,
                'opening_balance_minor_units' => $opening->minorUnits,
                'credit_limit_minor_units' => null,
                'is_active' => true,
            ]);
            $account->id = $data['id'];
            $account->owner()->associate($owner);
            $account->save();

            return $account;
        });

        return response()->json(['data' => ['id' => $account->id]], 201)->header('Cache-Control', 'no-store');
    }
}
