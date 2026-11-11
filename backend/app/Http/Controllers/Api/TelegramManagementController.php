<?php

namespace App\Http\Controllers\Api;

use App\Domain\Telegram\TelegramAuthorizationException;
use App\Domain\Telegram\TelegramBotClient;
use App\Domain\Telegram\TelegramDeliveryException;
use App\Domain\Telegram\TelegramOwnerLinkService;
use App\Http\Controllers\Controller;
use App\Models\TelegramOwnerLink;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramManagementController extends Controller
{
    public function ownerStatus(Request $request, TelegramOwnerLinkService $linkService): JsonResponse
    {
        $owner = $request->user();
        $configuredId = $linkService->configuredOwnerTelegramId();

        $link = TelegramOwnerLink::query()
            ->where('owner_id', $owner->getKey())
            ->where('is_active', true)
            ->whereNotNull('verified_at')
            ->first();

        return response()->json([
            'is_linked' => $link !== null,
            'configured_id_set' => $configuredId !== null,
            'telegram_user_id' => $link?->telegram_user_id,
            'verified_at' => $link?->verified_at?->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    public function linkOwner(Request $request, TelegramOwnerLinkService $linkService): JsonResponse
    {
        $owner = $request->user();
        if (! $owner instanceof User) {
            abort(401);
        }

        $configuredId = $linkService->configuredOwnerTelegramId();
        if ($configuredId === null) {
            return response()->json([
                'message' => 'TELEGRAM_OWNER_ID is not configured in the application environment.',
            ], 422)->header('Cache-Control', 'no-store');
        }

        try {
            $link = $linkService->linkVerifiedOwner($owner, $configuredId);
        } catch (TelegramAuthorizationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'message' => 'Telegram owner account linked successfully.',
            'telegram_user_id' => $link->telegram_user_id,
            'verified_at' => $link->verified_at?->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }

    public function registerWebhook(TelegramBotClient $botClient): JsonResponse
    {
        try {
            $response = $botClient->registerWebhook();
        } catch (TelegramDeliveryException $exception) {
            return response()->json([
                'message' => 'Failed to register webhook with Telegram Bot API.',
                'status_code' => $exception->httpStatus,
            ], 502)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'message' => 'Telegram webhook registered successfully.',
            'result' => $response,
        ])->header('Cache-Control', 'no-store');
    }

    public function webhookInfo(TelegramBotClient $botClient): JsonResponse
    {
        try {
            $info = $botClient->getWebhookInfo();
        } catch (TelegramDeliveryException $exception) {
            return response()->json([
                'message' => 'Failed to fetch webhook info from Telegram Bot API.',
                'status_code' => $exception->httpStatus,
            ], 502)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'data' => $info,
        ])->header('Cache-Control', 'no-store');
    }
}
