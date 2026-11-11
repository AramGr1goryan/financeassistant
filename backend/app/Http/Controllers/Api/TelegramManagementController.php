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

    public function status(TelegramBotClient $botClient, TelegramOwnerLinkService $linkService): JsonResponse
    {
        $botToken = config('services.telegram.bot_token');
        $botTokenConfigured = is_string($botToken) && trim($botToken) !== '';
        $configuredId = $linkService->configuredOwnerTelegramId();
        $webhookSecret = config('services.telegram.webhook_secret');
        $webhookSecretConfigured = is_string($webhookSecret) && trim((string) $webhookSecret) !== '';

        $isLinked = false;
        $userCount = 0;
        $ownerError = null;

        try {
            $userCount = User::query()->count();
        } catch (\Throwable $e) {
            $ownerError = 'User count error: '.$e->getMessage();
        }

        if ($configuredId !== null) {
            try {
                $link = TelegramOwnerLink::query()
                    ->where('telegram_user_id', $configuredId)
                    ->where('is_active', true)
                    ->whereNotNull('verified_at')
                    ->first();

                $isLinked = $link !== null;

                if (! $isLinked) {
                    $owner = User::query()->orderBy('id')->first();
                    if ($owner !== null) {
                        $linkService->linkVerifiedOwner($owner, $configuredId, $configuredId);
                        $isLinked = true;
                    } else {
                        $ownerError = 'No user exists in users table';
                    }
                }
            } catch (\Throwable $e) {
                $ownerError = 'Owner link error: '.$e->getMessage();
            }
        }

        $webhookInfo = null;
        $autoRegistered = false;
        if ($botTokenConfigured) {
            try {
                $webhookInfo = $botClient->getWebhookInfo();
                $expectedUrl = $botClient->resolveBaseAppUrl().'/api/v1/telegram/webhook';
                $currentUrl = (string) ($webhookInfo['url'] ?? '');

                if ($currentUrl !== $expectedUrl) {
                    $botClient->registerWebhook($expectedUrl);
                    $autoRegistered = true;
                    $webhookInfo = $botClient->getWebhookInfo();
                }
            } catch (\Throwable $e) {
                $webhookInfo = ['error' => $e->getMessage()];
            }
        }

        $linkRecord = null;
        $userRecord = null;
        $webhookUpdatesCount = null;
        $dbTransactionTest = null;
        $dbHost = null;
        $directDbHost = null;
        try {
            $url = config('database.connections.pgsql.url');
            if (is_string($url) && $url !== '') {
                $dbHost = parse_url($url, PHP_URL_HOST);
            }
            $directUrl = config('database.connections.pgsql_direct.url');
            if (is_string($directUrl) && $directUrl !== '') {
                $directDbHost = parse_url($directUrl, PHP_URL_HOST);
            }
        } catch (\Throwable) {
            // ignore
        }

        try {
            $link = TelegramOwnerLink::query()->first();
            if ($link !== null) {
                $linkRecord = [
                    'id' => $link->id,
                    'owner_id' => $link->owner_id,
                    'telegram_user_id' => $link->telegram_user_id,
                    'is_active' => $link->is_active,
                    'verified_at' => $link->verified_at?->toIso8601String(),
                    'owner_found' => ($link->owner !== null),
                ];
            }
            $user = User::query()->first();
            if ($user !== null) {
                $userRecord = [
                    'id' => $user->id,
                    'email' => $user->email,
                ];
            }
            $webhookUpdatesCount = \App\Models\TelegramWebhookUpdate::query()->count();

            \Illuminate\Support\Facades\DB::transaction(function () use ($user) {
                if ($user) {
                    User::query()->whereKey($user->getKey())->first();
                    \App\Models\TelegramWebhookUpdate::query()->where('update_id', 1)->first();
                }
            });
            $dbTransactionTest = 'ok';
        } catch (\Throwable $e) {
            $dbTransactionTest = 'DB error: '.$e->getMessage();
        }

        return response()->json([
            'status' => 'ok',
            'bot_token_configured' => $botTokenConfigured,
            'owner_id_configured' => $configuredId !== null,
            'webhook_secret_configured' => $webhookSecretConfigured,
            'user_count' => $userCount,
            'owner_linked' => $isLinked,
            'owner_error' => $ownerError,
            'link_record' => $linkRecord,
            'user_record' => $userRecord,
            'webhook_updates_count' => $webhookUpdatesCount,
            'db_host' => $dbHost,
            'direct_db_host' => $directDbHost,
            'db_transaction_test' => $dbTransactionTest,
            'auto_registered' => $autoRegistered,
            'webhook_info' => $webhookInfo,
        ])->header('Cache-Control', 'no-store');
    }
}

