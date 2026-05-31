<?php

namespace App\Filament\Pages;

use App\Models\DailySale;
use App\Models\DeliveryOrder;
use App\Models\OperationalCost;
use App\Models\SalesTarget;
use App\Models\StockItem;
use Filament\Pages\Page;

class MyBranchDashboard extends Page
{
    protected static string $view = 'filament.pages.my-branch-dashboard';

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Dashboard';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'My Branch Dashboard';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.branch_dashboard');
    }

    public function getDashboardData(): array
    {
        $user     = auth()->user();
        $branchId = $user->branch_id;

        $stocks = StockItem::where('branch_id', $branchId)
            ->orderByDesc('recorded_at')
            ->get()
            ->unique('cylinder_type')
            ->keyBy('cylinder_type');

        $todaySales    = (float) DailySale::where('branch_id', $branchId)->whereDate('sale_date', today())->sum('total_revenue');
        $todayQty      = (int)   DailySale::where('branch_id', $branchId)->whereDate('sale_date', today())->sum('quantity');
        $monthRevenue  = (float) DailySale::where('branch_id', $branchId)->whereBetween('sale_date', [now()->startOfMonth(), today()])->sum('total_revenue');
        $monthCosts    = (float) OperationalCost::where('branch_id', $branchId)->whereBetween('cost_date', [now()->startOfMonth(), today()])->sum('amount');

        $pendingDOs = DeliveryOrder::where('destination_branch_id', $branchId)
            ->where('status', 'pending_approval')
            ->count();

        $inTransitDOs = DeliveryOrder::with(['originBranch', 'expedition'])
            ->where('destination_branch_id', $branchId)
            ->whereIn('status', ['approved', 'in_transit'])
            ->orderByRaw('(eta IS NULL) ASC, eta ASC')
            ->get();

        $monthTargets   = SalesTarget::forMonth($branchId, now()->year, now()->month);
        $monthTarget    = (float) $monthTargets->sum('target_revenue');
        $achievementPct = $monthTarget > 0 ? round(($monthRevenue / $monthTarget) * 100, 1) : null;

        return compact('stocks', 'todaySales', 'todayQty', 'monthRevenue', 'monthCosts', 'pendingDOs', 'inTransitDOs', 'monthTarget', 'achievementPct');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isOwnerCabang() || $user?->isStaffGudang();
    }
}
