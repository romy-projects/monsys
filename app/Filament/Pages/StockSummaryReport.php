<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\StockItem;
use Filament\Pages\Page;

class StockSummaryReport extends Page
{
    protected static string $view = 'filament.pages.stock-summary-report';

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Stock Management';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Stock Summary — Empty vs Full';

    public ?int $branch_id = null;

    public static function getNavigationLabel(): string
    {
        return __('nav.item.stock_summary');
    }

    public function mount(): void
    {
        $user = auth()->user();

        if (! $user?->isOwnerPusat() && ! $user?->isRegionalLeader()) {
            $this->branch_id = $user->branch_id;
        }
    }

    public function getBranches(): \Illuminate\Database\Eloquent\Collection
    {
        $user = auth()->user();

        if ($user?->isOwnerPusat() || $user?->isRegionalLeader()) {
            return Branch::active()->orderBy('name')->get();
        }

        return Branch::where('id', $user?->branch_id)->get();
    }

    public function getStockMatrix(): array
    {
        $types = ['3kg', '5.5kg', '12kg', '50kg'];

        $branchQuery = Branch::active()->orderBy('name');

        if ($this->branch_id) {
            $branchQuery->where('id', $this->branch_id);
        } elseif (! auth()->user()?->isOwnerPusat() && ! auth()->user()?->isRegionalLeader()) {
            $branchQuery->where('id', auth()->user()?->branch_id);
        }

        $branches = $branchQuery->get();

        $latestStocks = StockItem::whereIn('branch_id', $branches->pluck('id'))
            ->get()
            ->sortByDesc('recorded_at')
            ->groupBy('branch_id')
            ->map(fn ($items) => $items->unique('cylinder_type')->keyBy('cylinder_type'));

        $rows   = [];
        $totals = array_fill_keys($types, ['full' => 0, 'empty' => 0, 'damaged' => 0]);

        foreach ($branches as $branch) {
            $stocks = $latestStocks->get($branch->id, collect());
            $row    = ['branch' => $branch];

            foreach ($types as $type) {
                $s = $stocks->get($type);
                $row[$type] = [
                    'full'    => $s?->qty_full    ?? null,
                    'empty'   => $s?->qty_empty   ?? null,
                    'damaged' => $s?->qty_damaged  ?? null,
                    'date'    => $s?->recorded_at,
                ];

                if ($s) {
                    $totals[$type]['full']    += $s->qty_full;
                    $totals[$type]['empty']   += $s->qty_empty;
                    $totals[$type]['damaged'] += $s->qty_damaged;
                }
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'totals' => $totals, 'types' => $types];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }
}
