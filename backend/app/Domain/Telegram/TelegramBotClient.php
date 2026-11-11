<?php

namespace App\Domain\Telegram;

use Illuminate\Support\Facades\Http;

final class TelegramBotClient
{
    public function sendMessage(int $chatId, string $text): void
    {
        $this->post('sendMessage', ['chat_id' => $chatId, 'text' => $text]);
    }

    public function sendWidget(int $chatId): void
    {
        $this->post('sendWidget', [
            'chat_id' => $chatId,
            'html' => TelegramFinanceWidget::html(),
            'caption' => 'Private finance summary. Tap a button to refresh.',
            'allow_queries' => true,
        ]);
    }

    public function sendAppButton(int $chatId, string $text): void
    {
        $url = $this->resolveBaseAppUrl();

        $this->post('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => [
                'inline_keyboard' => [[
                    ['text' => 'Open Finance Assistant', 'web_app' => ['url' => $url]],
                ]],
            ],
        ]);
    }

    public function answerWidgetQuery(string $queryId, string $text): void
    {
        if (strlen($text) > 1024) {
            $text = 'The result is too long for a widget. Use the corresponding bot command.';
        }

        $this->post('answerWidgetQuery', ['widget_query_id' => $queryId, 'data' => $text]);
    }

    /**
     * @return array<string, mixed>
     */
    public function registerWebhook(?string $url = null, ?string $secretToken = null): array
    {
        $baseUrl = $this->resolveBaseAppUrl();
        $webhookUrl = $url ?? ($baseUrl.'/api/v1/telegram/webhook');
        $secret = $secretToken ?? config('services.telegram.webhook_secret');

        $data = [
            'url' => $webhookUrl,
            'allowed_updates' => ['message', 'widget_query'],
        ];

        if (is_string($secret) && trim($secret) !== '') {
            $data['secret_token'] = trim($secret);
        }

        return $this->post('setWebhook', $data);
    }

    public function resolveBaseAppUrl(): string
    {
        $url = config('app.url');
        if (is_string($url) && $url !== '' && ! str_starts_with($url, 'http://localhost')) {
            if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
                throw new TelegramDeliveryException;
            }

            return rtrim($url, '/');
        }

        $vercelHost = env('VERCEL_PROJECT_PRODUCTION_URL') ?? env('VERCEL_URL');
        if (is_string($vercelHost) && trim($vercelHost) !== '') {
            return 'https://'.ltrim(trim($vercelHost), 'https://');
        }

        return 'https://financeassistant-kargt-eam.vercel.app';
    }

    /**
     * @return array<string, mixed>
     */
    public function getWebhookInfo(): array
    {
        $token = config('services.telegram.bot_token');

        if (! is_string($token) || trim($token) === '') {
            throw new TelegramDeliveryException;
        }

        $response = Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->get("https://api.telegram.org/bot{$token}/getWebhookInfo");

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new TelegramDeliveryException($response->status());
        }

        return (array) $response->json('result', []);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function post(string $method, array $data): array
    {
        $token = config('services.telegram.bot_token');

        if (! is_string($token) || trim($token) === '') {
            throw new TelegramDeliveryException;
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->post("https://api.telegram.org/bot{$token}/{$method}", $data);
        } catch (\Throwable $exception) {
            throw new TelegramDeliveryException(500, $exception->getMessage());
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            $desc = (string) $response->json('description', '');
            throw new TelegramDeliveryException($response->status(), $desc !== '' ? $desc : null);
        }

        return (array) $response->json();
    }
}
