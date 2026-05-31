<?php

namespace App\Filament\Widgets;

use App\Models\Branch;
use App\Models\DailySale;
use App\Models\DeliveryOrder;
use App\Models\StockItem;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $user = auth()->user();

        // Total stock (full cylinders) across visible branches
        $totalStock = StockItem::query()
            ->when(! $user?->isOwnerPusat() && ! $user?->isRegionalLeader(), fn ($q) =>
                $q->where('branch_id', $user?->branch_id)
            )
            ->sum('qty_full');

        // Today's sales total
        $todaySales = DailySale::query()
            ->whereDate('sale_date', today())
            ->when(! $user?->isOwnerPusat() && ! $user?->isRegionalLeader(), fn ($q) =>
                $q->where('branch_id', $user?->branch_id)
            )
            ->sum('total_revenue');

        // Pending DOs awaiting approval
        $pendingDo = DeliveryOrder::query()
            ->where('status', 'pending_approval')
            ->when(! $user?->isOwnerPusat() && ! $user?->isRegionalLeader(), fn ($q) =>
                $q->where('destination_branch_id', $user?->branch_id)
            )
            ->count();

        // Branches with low stock (< 50 full cylinders total)
        $lowStockCount = Branch::active()
            ->withSum('stockItems as total_full', 'qty_full')
            ->having('total_full', '<', 50)
            ->count();

        return [
            Stat::make('📦 ' . __('app.total_stock'), number_format($totalStock) . ' cylinders')
                ->description('Full cylinders across all branches')
                ->descriptionIcon('heroicon-m-cube')
                ->color('primary')
                ->chart([17, 21, 18, 24, 20, 22, 19, 26]),

            Stat::make('💰 ' . __('app.today_sales'), 'Rp ' . number_format($todaySales))
                ->description('Revenue generated today')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->chart([12, 15, 14, 18, 20, 17, 22, 19]),

            Stat::make('🚛 ' . __('app.pending_do'), $pendingDo . ' orders')
                ->description('Delivery orders awaiting approval')
                ->descriptionIcon('heroicon-m-clock')
                ->color($pendingDo > 0 ? 'warning' : 'success'),

            Stat::make('⚠️ ' . __('app.low_stock_branches'), $lowStockCount . ' branches')
                ->description('Branches with < 50 full cylinders')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($lowStockCount > 0 ? 'danger' : 'success'),
        ];
    }
}
