<?php

namespace App\Http\Controllers;

use App\Domain\Telegram\TelegramAuthorizationException;
use App\Domain\Telegram\TelegramPayloadException;
use App\Domain\Telegram\TelegramWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramWebhookProcessor $processor): JsonResponse
    {
        $secretHeader = $request->header('X-Telegram-Bot-Api-Secret-Token');

        try {
            $result = $processor->process(
                $request->json()->all(),
                is_string($secretHeader) ? $secretHeader : null,
            );
        } catch (TelegramAuthorizationException) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403)->header('Cache-Control', 'no-store');
        } catch (TelegramPayloadException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'status' => $result->duplicate ? 'duplicate' : 'processed',
            'duplicate' => $result->duplicate,
            'update_id' => $result->updateId,
            'chat_id' => $result->chatId,
            'command' => $result->command,
            'reply_text' => $result->replyText,
        ])->header('Cache-Control', 'no-store');
    }
}

