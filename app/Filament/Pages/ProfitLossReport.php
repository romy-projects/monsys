<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\DailySale;
use App\Models\LpgPrice;
use App\Models\OperationalCost;
use Filament\Pages\Page;

class ProfitLossReport extends Page
{
    protected static string $view = 'filament.pages.profit-loss-report';

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Reports';

    protected static ?string $navigationLabel = 'Profit & Loss';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Profit & Loss Report / Laporan Laba Rugi';

    public ?int $branch_id   = null;
    public string $start_date = '';
    public string $end_date   = '';

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn() => route('export.pl', array_filter([
                    'branch_id'  => $this->branch_id,
                    'start_date' => $this->start_date,
                    'end_date'   => $this->end_date,
                ])))
                ->openUrlInNewTab(),
        ];
    }

    public function mount(): void
    {
        $user = auth()->user();

        if (! $user?->canViewFinance()) {
            abort(403, 'You do not have access to financial reports.');
        }

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $this->branch_id = $user->branch_id;
        }

        $this->start_date = now()->startOfMonth()->toDateString();
        $this->end_date   = now()->toDateString();
    }

    public function getBranches(): \Illuminate\Database\Eloquent\Collection
    {
        $user = auth()->user();

        if ($user?->isOwnerPusat() || $user?->isRegionalLeader()) {
            return Branch::active()->orderBy('name')->get();
        }

        return Branch::where('id', $user?->branch_id)->get();
    }

    public function getReportData(): array
    {
        $branchId  = $this->branch_id;
        $startDate = $this->start_date ?: now()->startOfMonth()->toDateString();
        $endDate   = $this->end_date   ?: now()->toDateString();

        $revenueQuery = DailySale::query()
            ->whereBetween('sale_date', [$startDate, $endDate])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId));

        $totalRevenue = (float) $revenueQuery->sum('total_revenue');

        $revenueByType = DailySale::query()
            ->whereBetween('sale_date', [$startDate, $endDate])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->selectRaw('cylinder_type, SUM(total_revenue) as total, SUM(quantity) as qty')
            ->groupBy('cylinder_type')
            ->get()
            ->keyBy('cylinder_type');

        $costsByCategory = OperationalCost::query()
            ->whereBetween('cost_date', [$startDate, $endDate])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->selectRaw('cost_category, SUM(amount) as total')
            ->groupBy('cost_category')
            ->pluck('total', 'cost_category');

        // Calculate weighted-average HPP (COGS) for each cylinder type sold
        $totalHpp = 0.0;
        $hppDetails = [];
        foreach ($revenueByType as $type => $data) {
            $qtySold = (int) $data->qty;
            if ($qtySold <= 0) continue;

            // Get weighted average purchase_price within the report period
            $avgHpp = LpgPrice::where('cylinder_type', $type)
                ->when($branchId, fn($q) => $q->where('branch_id', $branchId)->orWhereNull('branch_id'))
                ->where('effective_date', '<=', $endDate)
                ->orderBy('effective_date', 'desc')
                ->limit(1)
                ->value('purchase_price');

            // If no branch-specific price found, try global
            if (! $avgHpp && $branchId) {
                $avgHpp = LpgPrice::where('cylinder_type', $type)
                    ->whereNull('branch_id')
                    ->where('effective_date', '<=', $endDate)
                    ->orderBy('effective_date', 'desc')
                    ->limit(1)
                    ->value('purchase_price');
            }

            $avgHpp = (float) ($avgHpp ?? 0);
            $totalHpp += $avgHpp * $qtySold;
            $hppDetails[$type] = [
                'qty'         => $qtySold,
                'avg_hpp'     => $avgHpp,
                'total_hpp'   => $avgHpp * $qtySold,
                'total_rev'   => (float) $data->total,
            ];
        }

        $totalCosts  = (float) $costsByCategory->sum();
        $totalExpenses = $totalHpp + $totalCosts;
        $grossProfit = $totalRevenue - $totalExpenses;
        $margin      = $totalRevenue > 0 ? round(($grossProfit / $totalRevenue) * 100, 1) : 0;

        $categoryLabels = [
            'fuel'      => '⛽ Fuel / BBM',
            'salary'    => '👷 Salary / Gaji',
            'logistics' => '🚛 Logistics / Ongkir',
            'levy'      => '🏛️ Levy / Retribusi',
            'other'     => '📋 Other / Lain-lain',
        ];

        return [
            'total_revenue'     => $totalRevenue,
            'revenue_by_type'   => $revenueByType,
            'hpp_details'       => $hppDetails,
            'total_hpp'         => $totalHpp,
            'costs_by_category' => $costsByCategory,
            'category_labels'   => $categoryLabels,
            'total_cost_ops'    => $totalCosts,
            'total_expenses'    => $totalExpenses,
            'gross_profit'      => $grossProfit,
            'margin'            => $margin,
            'start_date'        => $startDate,
            'end_date'          => $endDate,
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canViewFinance() ?? false;
    }
}
