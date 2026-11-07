<?php

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/status', fn () => response()->json(['status' => 'ok']))
        ->name('status');

    Route::post('/telegram/webhook', TelegramWebhookController::class)
        ->middleware('throttle:telegram-webhook')
        ->name('telegram.webhook');
});
