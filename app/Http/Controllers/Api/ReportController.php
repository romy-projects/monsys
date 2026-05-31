<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Branch;
use App\Models\DailySale;
use App\Models\DeliveryOrder;
use App\Models\LpgPrice;
use App\Models\OperationalCost;
use App\Models\Receivable;
use App\Models\StockItem;
use App\Models\StockMutation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    use ApiResponse;

    // ── Profit & Loss ─────────────────────────────────────────

    public function profitLoss(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canViewFinance()) return $this->forbidden();

        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate   = $request->input('end_date',   now()->endOfMonth()->toDateString());

        $query = fn ($model, $dateCol) => $model::query()
            ->when(! $user->isOwnerPusat() && ! $user->isRegionalLeader(), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when($request->filled('branch_id') && ($user->isOwnerPusat() || $user->isRegionalLeader()), fn ($q) => $q->where('branch_id', $request->branch_id))
            ->whereDate($dateCol, '>=', $startDate)
            ->whereDate($dateCol, '<=', $endDate);

        $revenue = (float) $query(DailySale::class, 'sale_date')->sum('total_revenue');
        $costs   = (float) $query(OperationalCost::class, 'cost_date')->sum('amount');

        $costsByCategory = $query(OperationalCost::class, 'cost_date')
            ->selectRaw('cost_category, SUM(amount) as total')
            ->groupBy('cost_category')
            ->pluck('total', 'cost_category')
            ->map(fn ($v) => (float) $v);

        $salesByType = $query(DailySale::class, 'sale_date')
            ->selectRaw('cylinder_type, SUM(total_revenue) as revenue, SUM(quantity) as qty')
            ->groupBy('cylinder_type')
            ->get()
            ->map(fn ($r) => ['cylinder_type' => $r->cylinder_type, 'revenue' => (float) $r->revenue, 'qty' => (int) $r->qty]);

        return $this->success([
            'period'           => ['start' => $startDate, 'end' => $endDate],
            'total_revenue'    => $revenue,
            'total_costs'      => $costs,
            'net_profit'       => $revenue - $costs,
            'margin_pct'       => $revenue > 0 ? round(($revenue - $costs) / $revenue * 100, 2) : 0,
            'revenue_by_type'  => $salesByType,
            'costs_by_category'=> $costsByCategory,
        ]);
    }

    // ── Stock Summary ─────────────────────────────────────────

    public function stockSummary(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = StockItem::query()->with('branch');

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where('branch_id', $user->branch_id);
        }

        $rows = $query->orderBy('branch_id')->orderBy('cylinder_type')->get();

        // Group by branch
        $grouped = $rows->groupBy('branch_id')->map(fn ($items, $branchId) => [
            'branch_id'   => $branchId,
            'branch_name' => $items->first()->branch?->name,
            'items'       => $items->map(fn ($s) => [
                'cylinder_type' => $s->cylinder_type,
                'qty_full'      => (int) $s->qty_full,
                'qty_empty'     => (int) $s->qty_empty,
                'qty_damaged'   => (int) $s->qty_damaged,
            ])->values(),
        ])->values();

        $totals = ['3kg' => 0, '5.5kg' => 0, '12kg' => 0, '50kg' => 0];
        foreach ($rows as $r) {
            $totals[$r->cylinder_type] = ($totals[$r->cylinder_type] ?? 0) + $r->qty_full;
        }

        return $this->success(['branches' => $grouped, 'totals_full' => $totals]);
    }

    // ── Shipment Tracking ─────────────────────────────────────

    public function shipmentTracking(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = DeliveryOrder::query()
            ->whereIn('status', ['approved', 'in_transit'])
            ->with(['originBranch', 'destinationBranch', 'expedition', 'vehicle']);

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where(fn ($q) => $q
                ->where('origin_branch_id', $user->branch_id)
                ->orWhere('destination_branch_id', $user->branch_id)
            );
        }

        $dos = $query->orderBy('eta')->get()->map(fn ($do) => [
            'id'             => $do->id,
            'do_number'      => $do->do_number,
            'cylinder_type'  => $do->cylinder_type,
            'quantity'       => (int) $do->quantity_ordered,
            'status'         => $do->status,
            'order_date'     => $do->order_date?->toDateString(),
            'eta'            => $do->eta?->toDateString(),
            'eta_overdue'    => $do->eta && $do->eta->lt(today()),
            'days_until_eta' => $do->eta ? today()->diffInDays($do->eta, false) : null,
            'origin'         => $do->order_type === 'supplier'
                ? ($do->supplier_name ?? 'Supplier')
                : $do->originBranch?->name,
            'destination'    => $do->destinationBranch?->name,
            'expedition'     => $do->expedition?->name,
            'vehicle'        => $do->vehicle ? [
                'plate_number' => $do->vehicle->plate_number,
                'driver_name'  => $do->vehicle->driver_name,
            ] : null,
        ]);

        return $this->success($dos);
    }

    // ── Sales Period ──────────────────────────────────────────

    public function salesPeriod(Request $request): JsonResponse
    {
        $user      = $request->user();
        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate   = $request->input('end_date',   now()->endOfMonth()->toDateString());

        $query = DailySale::query()
            ->when(! $user->isOwnerPusat() && ! $user->isRegionalLeader(), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when($request->filled('branch_id') && ($user->isOwnerPusat() || $user->isRegionalLeader()), fn ($q) => $q->where('branch_id', $request->branch_id))
            ->whereDate('sale_date', '>=', $startDate)
            ->whereDate('sale_date', '<=', $endDate);

        $daily = (clone $query)->selectRaw('sale_date, SUM(total_revenue) as revenue, SUM(quantity) as qty')
            ->groupBy('sale_date')->orderBy('sale_date')->get()
            ->map(fn ($r) => ['date' => $r->sale_date->toDateString(), 'revenue' => (float) $r->revenue, 'qty' => (int) $r->qty]);

        $byType = (clone $query)->selectRaw('cylinder_type, SUM(total_revenue) as revenue, SUM(quantity) as qty')
            ->groupBy('cylinder_type')->get()
            ->map(fn ($r) => ['cylinder_type' => $r->cylinder_type, 'revenue' => (float) $r->revenue, 'qty' => (int) $r->qty]);

        $byBuyer = (clone $query)->selectRaw('buyer_type, SUM(total_revenue) as revenue, SUM(quantity) as qty')
            ->groupBy('buyer_type')->get()
            ->map(fn ($r) => ['buyer_type' => $r->buyer_type, 'revenue' => (float) $r->revenue, 'qty' => (int) $r->qty]);

        return $this->success([
            'period'       => ['start' => $startDate, 'end' => $endDate],
            'total_revenue'=> (float) $query->sum('total_revenue'),
            'total_qty'    => (int) $query->sum('quantity'),
            'daily'        => $daily,
            'by_type'      => $byType,
            'by_buyer'     => $byBuyer,
        ]);
    }

    // ── Branch Ranking ────────────────────────────────────────

    public function branchRanking(Request $request): JsonResponse
    {
        $year  = (int) $request->input('year',  now()->year);
        $month = $request->input('month', 'all');

        $query = DailySale::query()->with('branch')
            ->selectRaw('branch_id, SUM(total_revenue) as revenue, SUM(quantity) as qty')
            ->whereYear('sale_date', $year);

        if ($month !== 'all') {
            $query->whereMonth('sale_date', (int) $month);
        }

        $rows = $query->groupBy('branch_id')->orderByDesc('revenue')->get();

        $grandTotal = $rows->sum('revenue');
        $ranked = $rows->values()->map(fn ($r, $i) => [
            'rank'         => $i + 1,
            'branch_id'    => $r->branch_id,
            'branch_name'  => $r->branch?->name,
            'revenue'      => (float) $r->revenue,
            'qty'          => (int) $r->qty,
            'share_pct'    => $grandTotal > 0 ? round($r->revenue / $grandTotal * 100, 1) : 0,
        ]);

        return $this->success([
            'year'        => $year,
            'month'       => $month,
            'grand_total' => (float) $grandTotal,
            'ranking'     => $ranked,
        ]);
    }

    // ── Stock Audit ───────────────────────────────────────────

    public function stockAudit(Request $request): JsonResponse
    {
        $user      = $request->user();
        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate   = $request->input('end_date',   now()->endOfMonth()->toDateString());

        $query = StockMutation::query()->with('branch')
            ->whereDate('mutation_date', '>=', $startDate)
            ->whereDate('mutation_date', '<=', $endDate);

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $rows = $query->orderBy('branch_id')->orderBy('mutation_date')->get();

        $grouped = $rows->groupBy('branch_id')->map(fn ($items, $branchId) => [
            'branch_id'   => $branchId,
            'branch_name' => $items->first()->branch?->name,
            'mutations'   => $items->map(fn ($m) => [
                'date'          => $m->mutation_date->toDateString(),
                'mutation_type' => $m->mutation_type,
                'cylinder_type' => $m->cylinder_type,
                'quantity'      => (int) $m->quantity,
                'reference_no'  => $m->reference_no,
            ])->values(),
            'totals' => [
                'in'         => $items->where('mutation_type', 'in')->sum('quantity'),
                'out'        => $items->where('mutation_type', 'out')->sum('quantity'),
                'transfer'   => $items->where('mutation_type', 'transfer')->sum('quantity'),
                'adjustment' => $items->where('mutation_type', 'adjustment')->sum('quantity'),
            ],
        ])->values();

        return $this->success([
            'period'  => ['start' => $startDate, 'end' => $endDate],
            'branches'=> $grouped,
        ]);
    }

    // ── HPP Report ────────────────────────────────────────────

    public function hpp(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canViewFinance()) return $this->forbidden();

        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate   = $request->input('end_date',   now()->endOfMonth()->toDateString());

        $salesQuery = DailySale::query()
            ->selectRaw('cylinder_type, SUM(quantity) as qty_sold, SUM(total_revenue) as revenue')
            ->when(! $user->isOwnerPusat() && ! $user->isRegionalLeader(), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->branch_id))
            ->whereDate('sale_date', '>=', $startDate)
            ->whereDate('sale_date', '<=', $endDate)
            ->groupBy('cylinder_type')
            ->get();

        $rows = $salesQuery->map(function ($r) use ($endDate) {
            $price    = LpgPrice::currentPrice($r->cylinder_type, $endDate);
            $hpp      = $price ? (float) $price->purchase_price * $r->qty_sold : null;
            $margin   = $hpp && $r->revenue > 0 ? $r->revenue - $hpp : null;
            $marginPct= $r->revenue > 0 && $margin !== null ? round($margin / $r->revenue * 100, 1) : null;

            return [
                'cylinder_type'  => $r->cylinder_type,
                'qty_sold'       => (int) $r->qty_sold,
                'revenue'        => (float) $r->revenue,
                'purchase_price' => $price ? (float) $price->purchase_price : null,
                'total_hpp'      => $hpp,
                'gross_margin'   => $margin,
                'margin_pct'     => $marginPct,
            ];
        });

        return $this->success([
            'period' => ['start' => $startDate, 'end' => $endDate],
            'rows'   => $rows,
        ]);
    }

    // ── Receivables Aging ─────────────────────────────────────

    public function receivablesAging(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canViewFinance()) return $this->forbidden();

        $query = Receivable::query()->with('branch')
            ->whereNotIn('status', ['paid']);

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        $receivables = $query->get();
        $today = today();

        $buckets = [
            'current'  => ['label' => 'Current (not due)',   'items' => collect()],
            '1_30'     => ['label' => 'Overdue 1–30 days',   'items' => collect()],
            '31_60'    => ['label' => 'Overdue 31–60 days',  'items' => collect()],
            '61_90'    => ['label' => 'Overdue 61–90 days',  'items' => collect()],
            'over_90'  => ['label' => 'Overdue > 90 days',   'items' => collect()],
        ];

        foreach ($receivables as $r) {
            $balance     = max(0, (float) $r->amount - (float) $r->paid_amount);
            $daysOverdue = $r->due_date ? max(0, $today->diffInDays($r->due_date, false) * -1) : 0;
            $item = [
                'id'           => $r->id,
                'branch_name'  => $r->branch?->name,
                'buyer_name'   => $r->buyer_name,
                'amount'       => (float) $r->amount,
                'balance'      => $balance,
                'due_date'     => $r->due_date?->toDateString(),
                'days_overdue' => (int) $daysOverdue,
                'status'       => $r->status,
            ];

            if ($daysOverdue <= 0) {
                $buckets['current']['items']->push($item);
            } elseif ($daysOverdue <= 30) {
                $buckets['1_30']['items']->push($item);
            } elseif ($daysOverdue <= 60) {
                $buckets['31_60']['items']->push($item);
            } elseif ($daysOverdue <= 90) {
                $buckets['61_90']['items']->push($item);
            } else {
                $buckets['over_90']['items']->push($item);
            }
        }

        $grandTotal = $receivables->sum(fn ($r) => max(0, (float) $r->amount - (float) $r->paid_amount));

        $result = collect($buckets)->map(fn ($b, $key) => [
            'key'         => $key,
            'label'       => $b['label'],
            'total'       => (float) $b['items']->sum('balance'),
            'count'       => $b['items']->count(),
            'share_pct'   => $grandTotal > 0 ? round($b['items']->sum('balance') / $grandTotal * 100, 1) : 0,
            'items'       => $b['items']->values(),
        ])->values();

        return $this->success([
            'grand_total' => (float) $grandTotal,
            'buckets'     => $result,
        ]);
    }
}
