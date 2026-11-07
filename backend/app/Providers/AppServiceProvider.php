<?php

namespace App\Providers;

use App\Domain\AiAssistant\AiAssistantProviderInterface;
use App\Domain\AiAssistant\NullAiAssistantProvider;
use App\Domain\Notifications\InMemoryNotificationTransport;
use App\Domain\Notifications\NotificationTransportInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(NotificationTransportInterface::class, InMemoryNotificationTransport::class);
        $this->app->bind(AiAssistantProviderInterface::class, NullAiAssistantProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('telegram-webhook', function (Request $request): Limit {
            $perMinute = max(1, (int) config('services.telegram.rate_limit_per_minute', 30));

            return Limit::perMinute($perMinute)->by((string) $request->ip());
        });
    }
}
