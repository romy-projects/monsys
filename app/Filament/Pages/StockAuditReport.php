<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\StockItem;
use App\Models\StockMutation;
use Filament\Pages\Page;

class StockAuditReport extends Page
{
    protected static string $view = 'filament.pages.stock-audit-report';

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Stock Audit Report / Laporan Audit Stok';

    public ?int $branch_id    = null;
    public string $start_date = '';
    public string $end_date   = '';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.report_audit');
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

    public function getAuditData(): array
    {
        $types     = ['3kg', '5.5kg', '12kg', '50kg'];
        $startDate = $this->start_date ?: now()->startOfMonth()->toDateString();
        $endDate   = $this->end_date   ?: now()->toDateString();
        $branchId  = $this->branch_id;
        $user      = auth()->user();

        $branchQuery = Branch::active()->orderBy('name');

        if ($branchId) {
            $branchQuery->where('id', $branchId);
        } elseif (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $branchQuery->where('id', $user->branch_id);
        }

        $branches = $branchQuery->get();

        $currentStocks = StockItem::whereIn('branch_id', $branches->pluck('id'))
            ->get()
            ->sortByDesc('recorded_at')
            ->groupBy('branch_id')
            ->map(fn ($items) => $items->unique('cylinder_type')->keyBy('cylinder_type'));

        // Net mutations within the period: masuk = +qty, keluar = -qty, adjustment = +qty (could be negative), transfer handled as keluar from source
        $mutations = StockMutation::whereIn('branch_id', $branches->pluck('id'))
            ->whereBetween('mutation_date', [$startDate, $endDate])
            ->get()
            ->groupBy(fn ($m) => $m->branch_id . '|' . $m->cylinder_type);

        $rows = [];

        foreach ($branches as $branch) {
            $stocks = $currentStocks->get($branch->id, collect());

            foreach ($types as $type) {
                $currentStock = $stocks->get($type);
                $key          = $branch->id . '|' . $type;
                $typeMutations = $mutations->get($key, collect());

                $netIn  = 0;
                $netOut = 0;

                foreach ($typeMutations as $m) {
                    if (in_array($m->mutation_type, ['masuk'])) {
                        $netIn += $m->quantity;
                    } elseif (in_array($m->mutation_type, ['keluar', 'transfer'])) {
                        $netOut += $m->quantity;
                    } else {
                        // adjustment: could be positive or negative
                        if ($m->quantity >= 0) {
                            $netIn += $m->quantity;
                        } else {
                            $netOut += abs($m->quantity);
                        }
                    }
                }

                $actualFull = $currentStock?->qty_full ?? null;

                $rows[] = [
                    'branch'       => $branch,
                    'type'         => $type,
                    'net_in'       => $netIn,
                    'net_out'      => $netOut,
                    'net_movement' => $netIn - $netOut,
                    'actual_full'  => $actualFull,
                    'recorded_at'  => $currentStock?->recorded_at,
                    'mutations_count' => $typeMutations->count(),
                ];
            }
        }

        return [
            'rows'       => $rows,
            'types'      => $types,
            'start_date' => $startDate,
            'end_date'   => $endDate,
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
