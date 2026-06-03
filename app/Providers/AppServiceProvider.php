<?php

namespace App\Providers;

use App\Models\DeliveryOrder;
use App\Models\StockItem;
use App\Observers\DeliveryOrderObserver;
use App\Observers\StockItemObserver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        StockItem::observe(StockItemObserver::class);
        DeliveryOrder::observe(DeliveryOrderObserver::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->id ?: $request->ip()
            );
        });
    }
}
