<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\DailySale;
use Filament\Pages\Page;

class SalesPeriodReport extends Page
{
    protected static string $view = 'filament.pages.sales-period-report';

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Sales Report / Laporan Penjualan';

    public ?int $branch_id    = null;
    public string $start_date = '';
    public string $end_date   = '';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.sales_report');
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
        $startDate = $this->start_date ?: now()->startOfMonth()->toDateString();
        $endDate   = $this->end_date   ?: now()->toDateString();
        $branchId  = $this->branch_id;

        $byType = DailySale::query()
            ->whereBetween('sale_date', [$startDate, $endDate])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('cylinder_type, SUM(quantity) as total_qty, SUM(total_revenue) as total_revenue, COUNT(DISTINCT sale_date) as days_active')
            ->groupBy('cylinder_type')
            ->get()
            ->keyBy('cylinder_type');

        $byDate = DailySale::query()
            ->whereBetween('sale_date', [$startDate, $endDate])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('sale_date, SUM(quantity) as total_qty, SUM(total_revenue) as total_revenue')
            ->groupBy('sale_date')
            ->orderBy('sale_date')
            ->get();

        $totalRevenue = (float) $byType->sum('total_revenue');
        $totalQty     = (int)   $byType->sum('total_qty');

        return [
            'by_type'       => $byType,
            'by_date'       => $byDate,
            'total_revenue' => $totalRevenue,
            'total_qty'     => $totalQty,
            'start_date'    => $startDate,
            'end_date'      => $endDate,
            'types'         => ['3kg', '5.5kg', '12kg', '50kg'],
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
