<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\DailySale;
use App\Models\SalesTarget;
use Carbon\Carbon;
use Filament\Pages\Page;

class BranchRanking extends Page
{
    protected static string $view = 'filament.pages.branch-ranking';

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Branch Sales Ranking';

    public string $start_date = '';
    public string $end_date   = '';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.sales_ranking');
    }

    public function mount(): void
    {
        $this->start_date = now()->startOfMonth()->toDateString();
        $this->end_date   = now()->toDateString();
    }

    public function getRankingData(): array
    {
        $user      = auth()->user();
        $startDate = $this->start_date ?: now()->startOfMonth()->toDateString();
        $endDate   = $this->end_date   ?: now()->toDateString();

        $query = DailySale::query()
            ->whereBetween('sale_date', [$startDate, $endDate])
            ->selectRaw('branch_id, SUM(total_revenue) as total_revenue, SUM(quantity) as total_qty')
            ->groupBy('branch_id')
            ->orderByDesc('total_revenue');

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where('branch_id', $user->branch_id);
        }

        $rows = $query->with('branch')->get();

        $grandTotal = (float) $rows->sum('total_revenue');

        // Build year/month pairs spanning the date range
        $cursor     = Carbon::parse($startDate)->startOfMonth();
        $endMonth   = Carbon::parse($endDate)->startOfMonth();
        $yearMonths = [];
        while ($cursor->lte($endMonth)) {
            $yearMonths[] = ['year' => $cursor->year, 'month' => $cursor->month];
            $cursor->addMonth();
        }

        // Sum targets per branch across those months
        $targetQuery = SalesTarget::query()
            ->where(function ($q) use ($yearMonths) {
                foreach ($yearMonths as $ym) {
                    $q->orWhere(fn($q2) => $q2->where('year', $ym['year'])->where('month', $ym['month']));
                }
            })
            ->selectRaw('branch_id, SUM(target_revenue) as target_revenue')
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        $ranked = $rows->values()->map(function ($row, $index) use ($grandTotal, $targetQuery) {
            $target = (float) ($targetQuery[$row->branch_id]?->target_revenue ?? 0);

            return [
                'rank'            => $index + 1,
                'branch'          => $row->branch,
                'total_revenue'   => (float) $row->total_revenue,
                'total_qty'       => (int)   $row->total_qty,
                'share'           => $grandTotal > 0 ? round(($row->total_revenue / $grandTotal) * 100, 1) : 0,
                'target_revenue'  => $target,
                'achievement_pct' => $target > 0 ? round(($row->total_revenue / $target) * 100, 1) : null,
            ];
        });

        return [
            'rows'         => $ranked,
            'grand_total'  => $grandTotal,
            'grand_target' => (float) $targetQuery->sum('target_revenue'),
            'start_date'   => $startDate,
            'end_date'     => $endDate,
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isOwnerPusat() ?? false;
    }
}
