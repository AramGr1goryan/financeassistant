<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserPasswordController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:12', 'max:72', 'confirmed'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The provided current password does not match.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['new_password']),
        ])->save();

        $request->session()->regenerate();

        return response()->json([
            'message' => 'Password updated successfully.',
        ])->header('Cache-Control', 'no-store');
    }
}
