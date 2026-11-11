<?php

namespace App\Http\Controllers;

use App\Domain\Telegram\TelegramAuthorizationException;
use App\Domain\Telegram\TelegramBotClient;
use App\Domain\Telegram\TelegramDeliveryException;
use App\Domain\Telegram\TelegramPayloadException;
use App\Domain\Telegram\TelegramWebhookProcessor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramWebhookProcessor $processor, TelegramBotClient $telegram): JsonResponse
    {
        $secretHeader = $request->header('X-Telegram-Bot-Api-Secret-Token');

        try {
            $result = $processor->process(
                $request->json()->all(),
                is_string($secretHeader) ? $secretHeader : null,
            );
        } catch (TelegramAuthorizationException $exception) {
            Log::warning('Telegram webhook authorization failed: '.$exception->getMessage());

            return response()->json([
                'message' => 'Forbidden.',
            ], 403)->header('Cache-Control', 'no-store');
        } catch (TelegramPayloadException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422)->header('Cache-Control', 'no-store');
        } catch (\Throwable $exception) {
            Log::error('Telegram webhook unexpected error: '.$exception->getMessage(), [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Internal server error.',
                'error' => $exception->getMessage(),
                'exception' => get_class($exception),
                'file' => basename($exception->getFile()).':'.$exception->getLine(),
            ], 500)->header('Cache-Control', 'no-store');
        }

        try {
            if ($result->widgetQueryId !== null) {
                $telegram->answerWidgetQuery($result->widgetQueryId, $result->replyText);
            } elseif ($result->command === '/widget') {
                $telegram->sendWidget($result->chatId);
            } elseif ($result->command === '/app') {
                $telegram->sendAppButton($result->chatId, $result->replyText);
            } else {
                $telegram->sendMessage($result->chatId, $result->replyText);
            }
        } catch (\Throwable $exception) {
            Log::warning('Telegram webhook reply delivery failed: '.$exception->getMessage(), [
                'update_id' => $result->updateId,
                'http_status' => $exception instanceof TelegramDeliveryException ? $exception->httpStatus : null,
            ]);

            return response()->json([
                'message' => 'Telegram reply delivery failed.',
            ], 503)->header('Cache-Control', 'no-store');
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
