<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\StockItem;
use App\Models\StockMutation;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

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

        if ($user !== null && ! $user->canAccessAllBranches()) {
            $this->branch_id = $user->branch_id && $user->canAccessBranch((int) $user->branch_id)
                ? (int) $user->branch_id
                : (count($user->accessibleBranchIds()) === 1 ? $user->accessibleBranchIds()[0] : null);
        }
    }

    public function getBranches(): Collection
    {
        $user = auth()->user();

        $query = Branch::active()->orderBy('name');
        $user?->applyAccessibleBranchScope($query, 'branches.id');

        return $query->get();
    }

    /**
     * Compute realtime stock per branch per cylinder type.
     *
     * Uses the latest stock_items record as a base (physical count),
     * then adjusts it with all stock_mutations that occurred AFTER that count date.
     * If no stock_items record exists, computes entirely from mutations.
     */
    public function getStockMatrix(): array
    {
        $types = ['3kg', '5.5kg', '12kg', '50kg'];

        $branchQuery = Branch::active()->orderBy('name');
        auth()->user()?->applyAccessibleBranchScope($branchQuery, 'branches.id');

        if ($this->branch_id) {
            $branchQuery->where('id', $this->branch_id);
        }

        $branches = $branchQuery->get();
        $branchIds = $branches->pluck('id');

        // Get latest stock_items record per branch + cylinder_type
        $latestStocks = StockItem::whereIn('branch_id', $branchIds)
            ->get()
            ->sortByDesc('recorded_at')
            ->groupBy('branch_id')
            ->map(function ($items) {
                return $items->groupBy('cylinder_type')->map->first();
            });

        // Get all stock_mutations for these branches
        $mutations = StockMutation::whereIn('branch_id', $branchIds)->get();

        $rows = [];
        $totals = array_fill_keys($types, ['full' => 0, 'empty' => 0, 'damaged' => 0]);

        foreach ($branches as $branch) {
            $branchStocks = $latestStocks->get($branch->id, collect());
            $branchMutations = $mutations->where('branch_id', $branch->id);

            $row = ['branch' => $branch];

            foreach ($types as $type) {
                $base = $branchStocks->get($type);

                // Start from latest physical count (if exists)
                $full = $base?->qty_full ?? 0;
                $empty = $base?->qty_empty ?? 0;
                $damaged = $base?->qty_damaged ?? 0;
                $baseDate = $base?->recorded_at;

                // Apply mutations that occurred AFTER the base record date,
                // OR all mutations if no base record exists
                $relevantMutations = $branchMutations->where('cylinder_type', $type);

                if ($baseDate) {
                    $relevantMutations = $relevantMutations->where('mutation_date', '>', $baseDate);
                }

                foreach ($relevantMutations as $mut) {
                    switch ($mut->mutation_type) {
                        case 'in':
                            $full += $mut->quantity;
                            break;
                        case 'out':
                            $full -= $mut->quantity;
                            break;
                        case 'transfer':
                            // Deduct from source, add to destination
                            if ($mut->source_branch_id === $branch->id) {
                                $full -= $mut->quantity;
                            }
                            if ($mut->destination_branch_id === $branch->id) {
                                $full += $mut->quantity;
                            }
                            break;
                            // adjustment: full qty is overridden by the physical count
                    }
                }

                // Clamp to zero (physical stock can't be negative)
                $full = max(0, $full);

                $row[$type] = [
                    'full' => $full,
                    'empty' => $empty,
                    'damaged' => $damaged,
                    'date' => $baseDate,
                ];

                $totals[$type]['full'] += $full;
                $totals[$type]['empty'] += $empty;
                $totals[$type]['damaged'] += $damaged;
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'totals' => $totals, 'types' => $types];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('reports.stock_summary.view') ?? false;
    }
}
