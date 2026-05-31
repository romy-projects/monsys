<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Branch;
use App\Models\DailySale;
use App\Models\DeliveryOrder;
use App\Models\OperationalCost;
use App\Models\StockItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    public function main(Request $request): JsonResponse
    {
        $user = $request->user();

        // KPI cards
        $todayRevenue = DailySale::whereDate('sale_date', today())->sum('total_revenue');
        $monthRevenue = DailySale::whereYear('sale_date', now()->year)
            ->whereMonth('sale_date', now()->month)->sum('total_revenue');
        $pendingDOs   = DeliveryOrder::where('status', 'pending_approval')->count();
        $activeDOs    = DeliveryOrder::whereIn('status', ['approved', 'in_transit'])->count();

        // Low stock alerts
        $lowStock = StockItem::where('qty_full', '<', 20)
            ->with('branch')
            ->orderBy('qty_full')
            ->get()
            ->map(fn ($s) => [
                'branch_id'     => $s->branch_id,
                'branch_name'   => $s->branch?->name,
                'cylinder_type' => $s->cylinder_type,
                'qty_full'      => $s->qty_full,
            ]);

        // Sales chart — last 14 days
        $chart = DailySale::selectRaw('sale_date, SUM(total_revenue) as revenue, SUM(quantity) as qty')
            ->whereDate('sale_date', '>=', now()->subDays(13))
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->get()
            ->map(fn ($r) => [
                'date'    => $r->sale_date->toDateString(),
                'revenue' => (float) $r->revenue,
                'qty'     => (int) $r->qty,
            ]);

        // Branch summary
        $branchCount   = Branch::where('status', 'active')->count();
        $stockSummary  = StockItem::selectRaw('cylinder_type, SUM(qty_full) as total_full, SUM(qty_empty) as total_empty')
            ->groupBy('cylinder_type')
            ->get()
            ->map(fn ($r) => [
                'cylinder_type' => $r->cylinder_type,
                'total_full'    => (int) $r->total_full,
                'total_empty'   => (int) $r->total_empty,
            ]);

        return $this->success([
            'stats' => [
                'today_revenue'    => (float) $todayRevenue,
                'month_revenue'    => (float) $monthRevenue,
                'pending_do_count' => $pendingDOs,
                'active_do_count'  => $activeDOs,
                'branch_count'     => $branchCount,
                'low_stock_count'  => $lowStock->count(),
            ],
            'low_stock_alerts' => $lowStock,
            'sales_chart'      => $chart,
            'stock_summary'    => $stockSummary,
        ]);
    }

    public function branch(Request $request): JsonResponse
    {
        $user     = $request->user();
        $branchId = $user->branch_id;

        if (! $branchId) {
            return $this->error('No branch assigned to this user.', 422);
        }

        $todayRevenue = DailySale::where('branch_id', $branchId)
            ->whereDate('sale_date', today())->sum('total_revenue');
        $monthRevenue = DailySale::where('branch_id', $branchId)
            ->whereYear('sale_date', now()->year)
            ->whereMonth('sale_date', now()->month)->sum('total_revenue');
        $monthCosts = OperationalCost::where('branch_id', $branchId)
            ->whereYear('cost_date', now()->year)
            ->whereMonth('cost_date', now()->month)->sum('amount');

        $stock = StockItem::where('branch_id', $branchId)->get()
            ->map(fn ($s) => [
                'cylinder_type' => $s->cylinder_type,
                'qty_full'      => (int) $s->qty_full,
                'qty_empty'     => (int) $s->qty_empty,
                'qty_damaged'   => (int) $s->qty_damaged,
                'alert_level'   => $s->qty_full < 20 ? 'danger' : ($s->qty_full < 50 ? 'warning' : 'ok'),
            ]);

        $incomingDOs = DeliveryOrder::where('destination_branch_id', $branchId)
            ->whereIn('status', ['approved', 'in_transit'])
            ->with('expedition')
            ->orderBy('eta')
            ->get()
            ->map(fn ($do) => [
                'id'            => $do->id,
                'do_number'     => $do->do_number,
                'cylinder_type' => $do->cylinder_type,
                'quantity'      => $do->quantity_ordered,
                'status'        => $do->status,
                'eta'           => $do->eta?->toDateString(),
                'expedition'    => $do->expedition?->name,
            ]);

        // Last 7 days sales
        $chart = DailySale::where('branch_id', $branchId)
            ->selectRaw('sale_date, SUM(total_revenue) as revenue, SUM(quantity) as qty')
            ->whereDate('sale_date', '>=', now()->subDays(6))
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->get()
            ->map(fn ($r) => [
                'date'    => $r->sale_date->toDateString(),
                'revenue' => (float) $r->revenue,
                'qty'     => (int) $r->qty,
            ]);

        return $this->success([
            'branch_id' => $branchId,
            'stats' => [
                'today_revenue'   => (float) $todayRevenue,
                'month_revenue'   => (float) $monthRevenue,
                'month_costs'     => (float) $monthCosts,
                'month_profit'    => (float) ($monthRevenue - $monthCosts),
                'incoming_do_count' => $incomingDOs->count(),
            ],
            'stock'        => $stock,
            'incoming_dos' => $incomingDOs,
            'sales_chart'  => $chart,
        ]);
    }
}
