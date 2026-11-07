<?php

use App\Http\Controllers\Api\BalanceController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\BudgetSettingController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Middleware\AuthenticateFinancialApi;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->name('api.v1.')->group(function (): void {
    Route::get('/session/csrf', [SessionController::class, 'csrf'])->name('session.csrf');
    Route::post('/session', [SessionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('session.store');

    Route::middleware(AuthenticateFinancialApi::class.':web')->group(function (): void {
        Route::delete('/session', [SessionController::class, 'destroy'])->name('session.destroy');
        Route::get('/balance', BalanceController::class)->name('balance');
        Route::get('/budget', BudgetController::class)->name('budget');
        Route::post('/budget/settings', BudgetSettingController::class)->name('budget.settings.store');
    });
});
