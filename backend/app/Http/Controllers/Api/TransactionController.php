<?php

namespace App\Http\Controllers\Api;

use App\Domain\FinancialCalendar;
use App\Domain\Money\Money;
use App\Domain\Transactions\TransactionProcessingException;
use App\Domain\Transactions\TransactionProcessor;
use App\Domain\Transactions\TransactionStatus;
use App\Domain\Transactions\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateManualTransactionRequest;
use App\Http\Requests\UpdateTransactionStatusRequest;
use App\Http\Resources\MoneyResource;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    public function index(Request $request, FinancialCalendar $calendar): JsonResponse
    {
        $transactions = Transaction::query()
            ->with('account:id,name')
            ->whereHas('account', fn ($query) => $query->where('owner_id', $request->user()->getAuthIdentifier()))
            ->orderByDesc('transaction_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Transaction $transaction): array => $this->resource($transaction, $calendar));

        return response()->json(['data' => $transactions])->header('Cache-Control', 'no-store');
    }

    public function store(
        CreateManualTransactionRequest $request,
        FinancialCalendar $calendar,
        TransactionProcessor $processor,
    ): JsonResponse {
        $data = $request->validated();
        $account = Account::query()
            ->whereKey($data['account_id'])
            ->where('owner_id', $request->user()->getAuthIdentifier())
            ->where('is_active', true)
            ->firstOrFail();

        $transactionAt = Carbon::createFromFormat(
            '!Y-m-d H:i:s',
            $data['transaction_date'].' 12:00:00',
            (string) config('finance.display_timezone', 'Asia/Yerevan'),
        )->utc();
        $status = TransactionStatus::from($data['status']);
        $existing = Transaction::query()
            ->where('account_id', $account->getKey())
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();
        $record = [
            'idempotency_key' => $data['idempotency_key'],
            'amount_minor_units' => $data['amount_minor_units'],
            'currency' => $account->currency,
            'currency_exponent' => $account->currency_exponent,
            'type' => $data['type'],
            'status' => $status->value,
            'transaction_at' => $transactionAt->toIso8601String(),
            'posting_at' => $status === TransactionStatus::Posted
                ? ($existing?->posting_at?->toIso8601String() ?? now('UTC')->toIso8601String())
                : null,
            'description' => $data['description'] ?? null,
            'merchant' => $data['merchant'] ?? null,
            'original_transaction_id' => $data['original_transaction_id'] ?? null,
        ];

        if ($data['type'] === TransactionType::Transfer->value) {
            $record['transfer_direction'] = $data['transfer_direction'];
            $record['transfer_counterpart_account_id'] = $data['transfer_counterpart_account_id'];
            $record['transfer_reference'] = 'manual:'.$data['idempotency_key'];
        }

        try {
            $result = $processor->process($account, $record);
        } catch (TransactionProcessingException $exception) {
            $statusCode = in_array($exception->errorCode, [
                'historical_transaction_conflict',
                'posting_date_conflict',
                'transfer_reference_conflict',
                'transfer_counterpart_conflict',
                'counterpart_transaction_conflict',
            ], true) ? 409 : 422;

            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
            ], $statusCode)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'data' => $this->resource($result->transaction->load('account:id,name'), $calendar),
            'was_created' => $result->wasCreated,
        ], $result->wasCreated ? 201 : 200)->header('Cache-Control', 'no-store');
    }

    public function updateStatus(
        UpdateTransactionStatusRequest $request,
        string $id,
        FinancialCalendar $calendar,
        TransactionProcessor $processor,
    ): JsonResponse {
        abort_unless(Str::isUuid($id), 404);
        $transaction = Transaction::query()
            ->whereKey($id)
            ->whereHas('account', fn ($query) => $query->where('owner_id', $request->user()->getAuthIdentifier()))
            ->with('account')
            ->firstOrFail();
        abort_if($transaction->is_generated_counterpart, 409, 'Update the primary transfer entry to change both transfer records.');
        $status = TransactionStatus::from($request->validated('status'));
        $record = [
            'idempotency_key' => $transaction->idempotency_key,
            'external_bank_transaction_id' => $transaction->external_bank_transaction_id,
            'amount_minor_units' => $transaction->amount_minor_units,
            'currency' => $transaction->currency,
            'currency_exponent' => $transaction->currency_exponent,
            'type' => $transaction->type->value,
            'status' => $status->value,
            'transaction_at' => $transaction->transaction_at->toIso8601String(),
            'posting_at' => $status === TransactionStatus::Posted
                ? ($transaction->posting_at?->toIso8601String() ?? now('UTC')->toIso8601String())
                : $transaction->posting_at?->toIso8601String(),
            'description' => $transaction->description,
            'merchant' => $transaction->merchant,
            'category_id' => $transaction->category_id,
            'original_transaction_id' => $transaction->original_transaction_id,
            'transfer_reference' => $transaction->transfer_reference,
            'transfer_direction' => $transaction->transfer_direction?->value,
            'metadata' => $transaction->metadata,
        ];
        if ($transaction->transfer_group_id !== null) {
            $record['transfer_counterpart_account_id'] = Transaction::query()
                ->where('transfer_group_id', $transaction->transfer_group_id)
                ->where('account_id', '!=', $transaction->account_id)
                ->value('account_id');
        }

        try {
            $result = $processor->process($transaction->account, $record);
        } catch (TransactionProcessingException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => $exception->errorCode,
            ], $exception->errorCode === 'invalid_status_transition' ? 409 : 422)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'data' => $this->resource($result->transaction->load('account:id,name'), $calendar),
        ])->header('Cache-Control', 'no-store');
    }

    private function resource(Transaction $transaction, FinancialCalendar $calendar): array
    {
        return [
            'id' => $transaction->id,
            'account_id' => $transaction->account_id,
            'account_name' => $transaction->account->name,
            'amount' => new MoneyResource(new Money(
                $transaction->amount_minor_units,
                $transaction->currency,
                $transaction->currency_exponent,
            )),
            'type' => $transaction->type->value,
            'status' => $transaction->status->value,
            'transaction_date' => $calendar->financialDate($transaction->transaction_at),
            'description' => $transaction->description ?? $transaction->merchant ?? $transaction->type->value,
            'merchant' => $transaction->merchant,
            'transfer_direction' => $transaction->transfer_direction?->value,
            'is_generated_counterpart' => $transaction->is_generated_counterpart,
        ];
    }
}
