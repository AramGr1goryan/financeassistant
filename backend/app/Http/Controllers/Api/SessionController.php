<?php

namespace App\Http\Controllers\Api;

use App\Domain\Telegram\TelegramOwnerLinkService;
use App\Http\Controllers\Controller;
use App\Models\TelegramOwnerLink;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function csrf(): Response
    {
        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): Response
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        if (! Auth::guard('web')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials are incorrect.',
            ]);
        }

        $request->session()->regenerate();

        $user = Auth::guard('web')->user();
        if ($user instanceof User) {
            $linkService = app(TelegramOwnerLinkService::class);
            $configuredId = $linkService->configuredOwnerTelegramId();
            if ($configuredId !== null && TelegramOwnerLink::query()->where('owner_id', $user->getKey())->doesntExist()) {
                try {
                    $linkService->linkVerifiedOwner($user, $configuredId);
                } catch (\Throwable) {
                    // Pre-existing link conflict or invalid state; keep login successful
                }
            }
        }

        return response()->noContent()->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent()->header('Cache-Control', 'no-store');
    }
}
