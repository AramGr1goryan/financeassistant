<?php

namespace App\Domain\Telegram;

use App\Models\TelegramWebhookUpdate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TelegramWebhookProcessor
{
    public function __construct(
        private readonly TelegramOwnerLinkService $ownerLinks,
        private readonly TelegramCommandHandler $commands,
    ) {}

    public function verifySecret(?string $providedSecret): void
    {
        $configuredSecret = config('services.telegram.webhook_secret');

        if (! is_string($configuredSecret)
            || trim($configuredSecret) === ''
            || ! is_string($providedSecret)
            || $providedSecret === ''
            || ! hash_equals($configuredSecret, $providedSecret)) {
            throw new TelegramAuthorizationException('Invalid Telegram webhook secret token.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function process(array $payload, ?string $providedSecret): TelegramWebhookResult
    {
        $this->verifySecret($providedSecret);

        $validated = $this->validatePayload($payload);
        $owner = $this->ownerLinks->resolveAuthorizedOwner(
            $validated['telegram_user_id'],
            $validated['telegram_chat_id'],
            $validated['chat_type'],
        );

        return DB::transaction(function () use ($owner, $validated): TelegramWebhookResult {
            $lockedOwner = User::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

            $existing = TelegramWebhookUpdate::query()
                ->where('update_id', $validated['update_id'])
                ->first();

            if ($existing !== null) {
                return new TelegramWebhookResult(
                    duplicate: true,
                    updateId: $existing->update_id,
                    chatId: $existing->telegram_chat_id,
                    command: $existing->command,
                    replyText: $existing->response_text,
                    widgetQueryId: $validated['widget_query_id'],
                );
            }

            $commandResult = $this->commands->handle($lockedOwner, $validated['text']);

            try {
                $record = new TelegramWebhookUpdate([
                    'update_id' => $validated['update_id'],
                    'telegram_user_id' => $validated['telegram_user_id'],
                    'telegram_chat_id' => $validated['telegram_chat_id'],
                    'command' => $commandResult->command,
                    'response_text' => $commandResult->replyText,
                    'processed_at' => Carbon::now('UTC'),
                ]);
                $record->owner()->associate($lockedOwner);
                $record->save();
            } catch (QueryException $exception) {
                $duplicate = TelegramWebhookUpdate::query()
                    ->where('update_id', $validated['update_id'])
                    ->first();

                if ($duplicate !== null) {
                    return new TelegramWebhookResult(
                        duplicate: true,
                        updateId: $duplicate->update_id,
                        chatId: $duplicate->telegram_chat_id,
                        command: $duplicate->command,
                        replyText: $duplicate->response_text,
                        widgetQueryId: $validated['widget_query_id'],
                    );
                }

                throw $exception;
            }

            return new TelegramWebhookResult(
                duplicate: false,
                updateId: $record->update_id,
                chatId: $record->telegram_chat_id,
                command: $record->command,
                replyText: $record->response_text,
                widgetQueryId: $validated['widget_query_id'],
            );
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     update_id: int,
     *     telegram_user_id: int,
     *     telegram_chat_id: int,
     *     chat_type: string,
     *     text: string,
     *     widget_query_id: ?string
     * }
     */
    private function validatePayload(array $payload): array
    {
        $updateId = $payload['update_id'] ?? null;

        if (! is_int($updateId) || $updateId <= 0) {
            throw new TelegramPayloadException('Telegram update_id must be a positive integer.');
        }

        if (array_key_exists('widget_query', $payload)) {
            return $this->validateWidgetQuery($updateId, $payload['widget_query']);
        }

        $message = $payload['message'] ?? null;

        if (! is_array($message)) {
            throw new TelegramPayloadException('Telegram update must contain a message object.');
        }

        $from = $message['from'] ?? null;
        $chat = $message['chat'] ?? null;
        $text = $message['text'] ?? null;

        if (! is_array($from) || ! is_int($from['id'] ?? null) || $from['id'] <= 0) {
            throw new TelegramPayloadException('Telegram message.from.id must be a positive integer.');
        }

        if (($from['is_bot'] ?? false) === true) {
            throw new TelegramPayloadException('Telegram bot messages are not accepted.');
        }

        if (! is_array($chat) || ! is_int($chat['id'] ?? null) || ! is_string($chat['type'] ?? null) || trim($chat['type']) === '') {
            throw new TelegramPayloadException('Telegram message.chat must include an integer id and non-empty type.');
        }

        if (! is_string($text) || trim($text) === '' || mb_strlen($text) > 4096) {
            throw new TelegramPayloadException('Telegram message.text must be a non-empty string up to 4096 characters.');
        }

        return [
            'update_id' => $updateId,
            'telegram_user_id' => $from['id'],
            'telegram_chat_id' => $chat['id'],
            'chat_type' => $chat['type'],
            'text' => $text,
            'widget_query_id' => null,
        ];
    }

    /**
     * @return array{update_id: int, telegram_user_id: int, telegram_chat_id: int, chat_type: string, text: string, widget_query_id: string}
     */
    private function validateWidgetQuery(int $updateId, mixed $query): array
    {
        if (! is_array($query)
            || ! is_string($query['id'] ?? null)
            || $query['id'] === ''
            || ! is_array($query['from'] ?? null)
            || ! is_int($query['from']['id'] ?? null)
            || $query['from']['id'] <= 0
            || ($query['from']['is_bot'] ?? false) === true
            || ! is_array($query['chat'] ?? null)
            || ! is_int($query['chat']['id'] ?? null)
            || ! is_string($query['chat']['type'] ?? null)
            || ! is_array($query['type'] ?? null)
            || ($query['type']['type'] ?? null) !== 'data'
            || ! is_string($query['data'] ?? null)) {
            throw new TelegramPayloadException('Invalid Telegram widget query.');
        }

        $command = match ($query['data']) {
            'balance' => '/balance',
            'budget' => '/budget',
            'accounts' => '/accounts',
            default => throw new TelegramPayloadException('Unsupported Telegram widget action.'),
        };

        return [
            'update_id' => $updateId,
            'telegram_user_id' => $query['from']['id'],
            'telegram_chat_id' => $query['chat']['id'],
            'chat_type' => $query['chat']['type'],
            'text' => $command,
            'widget_query_id' => $query['id'],
        ];
    }
}
