<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\BalanceController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\BudgetSettingController;
use App\Http\Controllers\Api\IncomeExpectationController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\TelegramAuthController;
use App\Http\Controllers\Api\TelegramManagementController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UserPasswordController;
use App\Http\Middleware\AuthenticateFinancialApi;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->name('api.v1.')->group(function (): void {
    Route::get('/session/csrf', [SessionController::class, 'csrf'])->name('session.csrf');
    Route::post('/session', [SessionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('session.store');
    Route::post('/telegram/auth', [TelegramAuthController::class, 'authenticate'])
        ->middleware('throttle:10,1')
        ->name('telegram.auth');

    Route::middleware(AuthenticateFinancialApi::class.':web')->group(function (): void {
        Route::delete('/session', [SessionController::class, 'destroy'])->name('session.destroy');
        Route::put('/user/password', [UserPasswordController::class, 'update'])->name('user.password.update');
        Route::post('/telegram/link-owner', [TelegramManagementController::class, 'linkOwner'])->name('telegram.link-owner');
        Route::get('/telegram/owner-status', [TelegramManagementController::class, 'ownerStatus'])->name('telegram.owner-status');
        Route::post('/telegram/register-webhook', [TelegramManagementController::class, 'registerWebhook'])->name('telegram.register-webhook');
        Route::get('/telegram/webhook-info', [TelegramManagementController::class, 'webhookInfo'])->name('telegram.webhook-info');

        Route::get('/balance', BalanceController::class)->name('balance');
        Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
        Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
        Route::post('/transactions', [TransactionController::class, 'store'])->name('transactions.store');
        Route::post('/transactions/{id}/status', [TransactionController::class, 'updateStatus'])->name('transactions.status.update');
        Route::get('/income-expectations', [IncomeExpectationController::class, 'index'])->name('income-expectations.index');
        Route::post('/income-expectations', [IncomeExpectationController::class, 'store'])->name('income-expectations.store');
        Route::post('/income-expectations/{id}/complete', [IncomeExpectationController::class, 'complete'])->name('income-expectations.complete');
        Route::get('/budget', BudgetController::class)->name('budget');
        Route::post('/budget/settings', BudgetSettingController::class)->name('budget.settings.store');
    });
});
