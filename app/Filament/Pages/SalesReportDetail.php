<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Support\SalesDetailReport;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

/**
 * Phase 19A / T19A-08: sales detail with the payment information the client's
 * SALES REPORT sheet needs (TGL. BAYAR PANGKALAN + METODE PEMBAYARAN).
 */
class SalesReportDetail extends Page
{
    protected static string $view = 'filament.pages.sales-report-detail';

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Sales Detail / Laporan Penjualan Detail';

    public ?int $branch_id = null;

    public string $start_date = '';

    public string $end_date = '';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.finance_sales_detail');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('reports.sales_detail.view') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasPermission('reports.sales_detail.view') ?? false;
    }

    public function mount(): void
    {
        $user = auth()->user();

        if ($user !== null && ! $user->canAccessAllBranches()) {
            $this->branch_id = $user->branch_id && $user->canAccessBranch((int) $user->branch_id)
                ? (int) $user->branch_id
                : (count($user->accessibleBranchIds()) === 1 ? $user->accessibleBranchIds()[0] : null);
        }

        $this->start_date = now()->startOfMonth()->toDateString();
        $this->end_date = now()->toDateString();
    }

    public function getBranches(): Collection
    {
        $user = auth()->user();

        $query = Branch::active()->orderBy('name');
        $user?->applyAccessibleBranchScope($query, 'branches.id');

        return $query->get();
    }

    /** @return array{rows: array<int, array<string, mixed>>, totals: array<string, float>} */
    public function getReport(): array
    {
        return SalesDetailReport::build(
            $this->branch_id,
            $this->start_date ?: now()->startOfMonth()->toDateString(),
            $this->end_date ?: now()->toDateString(),
        );
    }

    public function getExportUrl(): string
    {
        return route('export.sales_detail', array_filter([
            'branch_id' => $this->branch_id,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
        ]));
    }
}
