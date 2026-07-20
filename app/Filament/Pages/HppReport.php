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

        // Get all sales in the period for date-matched HPP calculation
        $allSales = DailySale::query()
            ->whereBetween('sale_date', [$startDate, $endDate])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->select(['sale_date', 'cylinder_type', 'quantity'])
            ->get();

        // Sales grouped by cylinder_type for the period
        $salesByType = DailySale::query()
            ->whereBetween('sale_date', [$startDate, $endDate])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->selectRaw('cylinder_type, SUM(quantity) as total_qty, SUM(total_revenue) as total_revenue')
            ->groupBy('cylinder_type')
            ->get()
            ->keyBy('cylinder_type');

        // Pre-load all prices for this branch (or global) sorted by date
        $allPrices = LpgPrice::whereNull('branch_id')
            ->whereIn('cylinder_type', $types)
            ->orderBy('cylinder_type')
            ->orderBy('effective_date')
            ->get();

        if ($branchId) {
            $branchPrices = LpgPrice::where('branch_id', $branchId)
                ->whereIn('cylinder_type', $types)
                ->orderBy('cylinder_type')
                ->orderBy('effective_date')
                ->get();
        } else {
            $branchPrices = collect();
        }

        $rows = collect($types)->map(function ($type) use ($allSales, $salesByType, $allPrices, $branchPrices, $startDate, $endDate) {
            $sale  = $salesByType->get($type);

            $qtySold      = (int)   ($sale?->total_qty      ?? 0);
            $totalRevenue = (float) ($sale?->total_revenue  ?? 0);

            // Get the latest price effective on start_date for display
            $startPrice = $branchPrices
                ->where('cylinder_type', $type)
                ->where('effective_date', '<=', $startDate)
                ->last();

            if (! $startPrice) {
                $startPrice = $allPrices
                    ->where('cylinder_type', $type)
                    ->where('effective_date', '<=', $startDate)
                    ->last();
            }

            // Get the latest price effective on end_date for display
            $endPrice = $branchPrices
                ->where('cylinder_type', $type)
                ->where('effective_date', '<=', $endDate)
                ->last();

            if (! $endPrice) {
                $endPrice = $allPrices
                    ->where('cylinder_type', $type)
                    ->where('effective_date', '<=', $endDate)
                    ->last();
            }

            // Calculate actual HPP matching each sale's date
            $typeSales     = $allSales->where('cylinder_type', $type);
            $totalHpp      = 0;
            foreach ($typeSales as $ts) {
                $saleDate = $ts->sale_date->toDateString();
                $qty      = (int) $ts->quantity;

                $priceAtSale = $branchPrices
                    ->where('cylinder_type', $type)
                    ->where('effective_date', '<=', $saleDate)
                    ->last();

                if (! $priceAtSale) {
                    $priceAtSale = $allPrices
                        ->where('cylinder_type', $type)
                        ->where('effective_date', '<=', $saleDate)
                        ->last();
                }

                $hppPerUnit = $priceAtSale ? (float) $priceAtSale->purchase_price : 0;
                $totalHpp  += $hppPerUnit * $qty;
            }

            $purchasePriceStart = $startPrice ? (float) $startPrice->purchase_price : 0;
            $purchasePriceEnd   = $endPrice   ? (float) $endPrice->purchase_price : 0;
            $sellingPriceStart  = $startPrice ? (float) $startPrice->selling_price : 0;
            $sellingPriceEnd    = $endPrice   ? (float) $endPrice->selling_price : 0;

            $grossMargin = $totalRevenue - $totalHpp;
            $marginPct   = $totalRevenue > 0 ? round(($grossMargin / $totalRevenue) * 100, 1) : null;

            return [
                'type'               => $type,
                'qty_sold'           => $qtySold,
                'purchase_price_start' => $purchasePriceStart,
                'purchase_price_end'   => $purchasePriceEnd,
                'selling_price_start'  => $sellingPriceStart,
                'selling_price_end'    => $sellingPriceEnd,
                'total_hpp'          => $totalHpp,
                'total_revenue'      => $totalRevenue,
                'gross_margin'       => $grossMargin,
                'margin_pct'         => $marginPct,
                'has_data'           => $sale  !== null,
                'has_price'          => $startPrice !== null,
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
