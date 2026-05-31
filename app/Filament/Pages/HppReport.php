<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\DailySale;
use App\Models\LpgPrice;
use Filament\Pages\Page;

class HppReport extends Page
{
    protected static string $view = 'filament.pages.hpp-report';

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'HPP Report / Laporan Harga Pokok';

    public ?int $branch_id    = null;
    public string $start_date = '';
    public string $end_date   = '';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.finance_cogs');
    }

    public function mount(): void
    {
        $user = auth()->user();

        if (! $user?->isOwnerPusat() && ! $user?->isRegionalLeader()) {
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
        $types     = ['3kg', '5.5kg', '12kg', '50kg'];
        $startDate = $this->start_date ?: now()->startOfMonth()->toDateString();
        $endDate   = $this->end_date   ?: now()->toDateString();
        $branchId  = $this->branch_id;

        // Get purchase price effective at end_date for each type
        $prices = collect($types)->mapWithKeys(function ($type) use ($endDate) {
            $price = LpgPrice::where('cylinder_type', $type)
                ->where('effective_date', '<=', $endDate)
                ->latest('effective_date')
                ->first();
            return [$type => $price];
        });

        // Sales grouped by cylinder_type for the period
        $salesByType = DailySale::query()
            ->whereBetween('sale_date', [$startDate, $endDate])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('cylinder_type, SUM(quantity) as total_qty, SUM(total_revenue) as total_revenue')
            ->groupBy('cylinder_type')
            ->get()
            ->keyBy('cylinder_type');

        $rows = collect($types)->map(function ($type) use ($salesByType, $prices) {
            $sale  = $salesByType->get($type);
            $price = $prices->get($type);

            $qtySold       = (int)   ($sale?->total_qty      ?? 0);
            $totalRevenue  = (float) ($sale?->total_revenue  ?? 0);
            $purchasePrice = (float) ($price?->purchase_price ?? 0);
            $sellingPrice  = (float) ($price?->selling_price  ?? 0);
            $totalHpp      = $qtySold * $purchasePrice;
            $grossMargin   = $totalRevenue - $totalHpp;
            $marginPct     = $totalRevenue > 0 ? round(($grossMargin / $totalRevenue) * 100, 1) : null;

            return [
                'type'           => $type,
                'qty_sold'       => $qtySold,
                'purchase_price' => $purchasePrice,
                'selling_price'  => $sellingPrice,
                'total_hpp'      => $totalHpp,
                'total_revenue'  => $totalRevenue,
                'gross_margin'   => $grossMargin,
                'margin_pct'     => $marginPct,
                'has_data'       => $sale  !== null,
                'has_price'      => $price !== null,
            ];
        });

        $totals = [
            'qty_sold'      => $rows->sum('qty_sold'),
            'total_hpp'     => $rows->sum('total_hpp'),
            'total_revenue' => $rows->sum('total_revenue'),
            'gross_margin'  => $rows->sum('gross_margin'),
            'margin_pct'    => $rows->sum('total_revenue') > 0
                ? round(($rows->sum('gross_margin') / $rows->sum('total_revenue')) * 100, 1)
                : null,
        ];

        return [
            'rows'       => $rows,
            'totals'     => $totals,
            'start_date' => $startDate,
            'end_date'   => $endDate,
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canViewFinance() ?? false;
    }
}
