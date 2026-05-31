<?php

namespace App\Providers;

use App\Models\DeliveryOrder;
use App\Models\StockItem;
use App\Observers\DeliveryOrderObserver;
use App\Observers\StockItemObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        StockItem::observe(StockItemObserver::class);
        DeliveryOrder::observe(DeliveryOrderObserver::class);
    }
}
