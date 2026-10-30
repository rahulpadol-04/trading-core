<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('orders', function (Request $request): array {
            $key = 'orders:'.($request->user()->id ?? $request->ip());

            return [
                Limit::perSecond(5)->by($key.':second'),
                Limit::perMinute(config('trading.order_rate_per_minute'))->by($key.':minute'),
            ];
        });
    }
}
