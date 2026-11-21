<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\DailySale;
use App\Models\DeliveryOrder;
use App\Models\OperationalCost;
use App\Models\Role;
use App\Models\SalesTarget;
use App\Models\StockItem;
use App\Models\User;
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
        $branchId = static::resolveBranchId(auth()->user());

        // `canAccess()` refuses accounts without a single branch context, but a
        // direct visit must not 500 either: return an empty dashboard instead.
        if ($branchId === null) {
            return [
                'branch' => null,
                'stocks' => collect(),
                'todaySales' => 0.0,
                'todayQty' => 0,
                'monthRevenue' => 0.0,
                'monthCosts' => 0.0,
                'pendingDOs' => 0,
                'inTransitDOs' => collect(),
                'monthTarget' => 0.0,
                'achievementPct' => null,
            ];
        }

        $branch = Branch::find($branchId);

        $stocks = StockItem::where('branch_id', $branchId)
            ->orderByDesc('recorded_at')
            ->get()
            ->unique('cylinder_type')
            ->keyBy('cylinder_type');

        $todaySales = (float) DailySale::where('branch_id', $branchId)->whereDate('sale_date', today())->sum('total_revenue');
        $todayQty = (int) DailySale::where('branch_id', $branchId)->whereDate('sale_date', today())->sum('quantity');
        $monthRevenue = (float) DailySale::where('branch_id', $branchId)->whereBetween('sale_date', [now()->startOfMonth(), today()])->sum('total_revenue');
        $monthCosts = (float) OperationalCost::where('branch_id', $branchId)->whereBetween('cost_date', [now()->startOfMonth(), today()])->sum('amount');

        $pendingDOs = DeliveryOrder::purchaseOrders()
            ->where('destination_branch_id', $branchId)
            ->where('status', 'pending_approval')
            ->count();

        $inTransitDOs = DeliveryOrder::purchaseOrders()
            ->with(['originBranch', 'expedition'])
            ->where('destination_branch_id', $branchId)
            ->whereIn('status', ['approved', 'in_transit'])
            ->orderByRaw('(eta IS NULL) ASC, eta ASC')
            ->get();

        $monthTargets = SalesTarget::forMonth($branchId, now()->year, now()->month);
        $monthTarget = (float) $monthTargets->sum('target_revenue');
        $achievementPct = $monthTarget > 0 ? round(($monthRevenue / $monthTarget) * 100, 1) : null;

        return compact('branch', 'stocks', 'todaySales', 'todayQty', 'monthRevenue', 'monthCosts', 'pendingDOs', 'inTransitDOs', 'monthTarget', 'achievementPct');
    }

    /**
     * The single branch this page reports on: the user's own post when they may
     * open it, otherwise their only assigned branch.
     *
     * Null means the account has no single branch context. The Super Admin is
     * posted at HQ, so it always resolves; an owner_pusat without a post does
     * not, and that null branch used to reach `SalesTarget::forMonth(null)` and
     * 500 the page.
     */
    protected static function resolveBranchId(?User $user): ?int
    {
        if ($user === null) {
            return null;
        }

        if ($user->branch_id !== null && $user->canAccessBranch((int) $user->branch_id)) {
            return (int) $user->branch_id;
        }

        // Only an explicitly assigned branch can stand in for a missing post:
        // an all-branches account has no single branch to report on.
        $user->loadMissing('accessRole');

        if ($user->accessRole?->data_scope !== Role::SCOPE_ASSIGNED_BRANCHES) {
            return null;
        }

        $branchIds = $user->accessibleBranchIds();

        return count($branchIds) === 1 ? (int) $branchIds[0] : null;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->hasPermission('dashboard.branch.view') ?? false)
            && static::resolveBranchId($user) !== null;
    }
}
