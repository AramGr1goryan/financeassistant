<?php

namespace App\Http\Controllers\Api;

use App\Domain\Telegram\TelegramAuthorizationException;
use App\Domain\Telegram\TelegramAuthService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TelegramAuthController extends Controller
{
    public function authenticate(Request $request, TelegramAuthService $authService): JsonResponse
    {
        $validated = $request->validate([
            'init_data' => ['required', 'string', 'max:8192'],
        ]);

        try {
            $owner = $authService->authenticateInitData($validated['init_data']);
        } catch (TelegramAuthorizationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 401)->header('Cache-Control', 'no-store');
        }

        Auth::guard('web')->login($owner);
        $request->session()->regenerate();

        return response()->json([
            'authenticated' => true,
            'user' => [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
