<?php

namespace App\Domain\Notifications;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class NotificationService
{
    private const FORBIDDEN_PAYLOAD_KEYS = [
        'password',
        'pin',
        'cvv',
        'cvc',
        'token',
        'access_token',
        'refresh_token',
        'secret',
        'webhook_secret',
        'bot_token',
        'card_number',
        'pan',
        'raw_payload',
    ];

    public function __construct(
        private readonly NotificationTransportInterface $transport,
    ) {}

    public function setPreference(
        User $owner,
        NotificationCategory $category,
        bool $isEnabled,
        NotificationChannel $channel = NotificationChannel::Telegram,
    ): NotificationPreference {
        return DB::transaction(function () use ($owner, $category, $isEnabled, $channel): NotificationPreference {
            $lockedOwner = User::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

            $preference = NotificationPreference::query()
                ->where('owner_id', $lockedOwner->getKey())
                ->where('channel', $channel->value)
                ->where('category', $category->value)
                ->lockForUpdate()
                ->first();

            if ($preference === null) {
                $preference = new NotificationPreference([
                    'channel' => $channel,
                    'category' => $category,
                    'is_enabled' => $isEnabled,
                ]);
                $preference->owner()->associate($lockedOwner);
            } else {
                $preference->is_enabled = $isEnabled;
            }

            $preference->save();

            return $preference;
        });
    }

    public function isEnabled(
        User $owner,
        NotificationCategory $category,
        NotificationChannel $channel = NotificationChannel::Telegram,
    ): bool {
        $preference = NotificationPreference::query()
            ->where('owner_id', $owner->getKey())
            ->where('channel', $channel->value)
            ->where('category', $category->value)
            ->first();

        return $preference === null ? true : $preference->is_enabled;
    }

    /**
     * @return Collection<int, NotificationPreference>
     */
    public function preferencesForOwner(User $owner): Collection
    {
        return NotificationPreference::query()
            ->where('owner_id', $owner->getKey())
            ->orderBy('channel')
            ->orderBy('category')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(
        User $owner,
        NotificationCategory $category,
        string $deduplicationKey,
        string $message,
        array $payload = [],
        NotificationChannel $channel = NotificationChannel::Telegram,
    ): NotificationDispatchResult {
        $normalizedKey = trim($deduplicationKey);
        $normalizedMessage = trim($message);

        if ($normalizedKey === '' || mb_strlen($normalizedKey) > 191) {
            throw new InvalidArgumentException('Notification deduplication key must contain between 1 and 191 characters.');
        }

        if ($normalizedMessage === '' || mb_strlen($normalizedMessage) > 4096) {
            throw new InvalidArgumentException('Notification message must contain between 1 and 4096 characters.');
        }

        $this->assertSafePayload($payload);

        return DB::transaction(function () use (
            $owner,
            $category,
            $normalizedKey,
            $normalizedMessage,
            $payload,
            $channel,
        ): NotificationDispatchResult {
            $lockedOwner = User::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

            $existing = Notification::query()
                ->where('owner_id', $lockedOwner->getKey())
                ->where('deduplication_key', $normalizedKey)
                ->first();

            if ($existing !== null) {
                return new NotificationDispatchResult(
                    notification: $existing,
                    wasDuplicate: true,
                    wasDelivered: false,
                );
            }

            $enabled = $this->isEnabled($lockedOwner, $category, $channel);

            try {
                $notification = new Notification([
                    'channel' => $channel,
                    'category' => $category,
                    'deduplication_key' => $normalizedKey,
                    'message' => $normalizedMessage,
                    'payload' => $payload === [] ? null : $payload,
                    'status' => $enabled ? NotificationDeliveryStatus::Pending : NotificationDeliveryStatus::Suppressed,
                    'delivered_at' => null,
                ]);
                $notification->owner()->associate($lockedOwner);
                $notification->save();
            } catch (QueryException $exception) {
                $duplicate = Notification::query()
                    ->where('owner_id', $lockedOwner->getKey())
                    ->where('deduplication_key', $normalizedKey)
                    ->first();

                if ($duplicate !== null) {
                    return new NotificationDispatchResult(
                        notification: $duplicate,
                        wasDuplicate: true,
                        wasDelivered: false,
                    );
                }

                throw $exception;
            }

            if (! $enabled) {
                return new NotificationDispatchResult(
                    notification: $notification,
                    wasDuplicate: false,
                    wasDelivered: false,
                );
            }

            $this->transport->send($lockedOwner, $channel, $normalizedMessage);

            $notification->status = NotificationDeliveryStatus::Sent;
            $notification->delivered_at = Carbon::now('UTC');
            $notification->save();

            return new NotificationDispatchResult(
                notification: $notification,
                wasDuplicate: false,
                wasDelivered: true,
            );
        });
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function assertSafePayload(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized = strtolower(trim($key));

                foreach (self::FORBIDDEN_PAYLOAD_KEYS as $forbidden) {
                    if ($normalized === $forbidden || str_contains($normalized, $forbidden)) {
                        throw new InvalidArgumentException("Notification payload must not contain sensitive key [{$key}].");
                    }
                }
            }

            if (is_array($value)) {
                $this->assertSafePayload($value);
            }
        }
    }
}

