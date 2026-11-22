<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Support\JournalMapping;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

/**
 * Phase 19A / T19A-07: Jurnal Umum built from the operational tables
 * (no GL posting tables yet — see docs/lapkeu-gap-analysis.md §3 Option C).
 */
class JournalExport extends Page
{
    protected static string $view = 'filament.pages.journal-export';

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Jurnal Umum / General Journal';

    public ?int $branch_id = null;

    public string $start_date = '';

    public string $end_date = '';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.finance_journal');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('reports.journal.view') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasPermission('reports.journal.view') ?? false;
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

    /** @return array<int, array<string, mixed>> */
    public function getRows(): array
    {
        return JournalMapping::build(
            $this->branch_id,
            $this->start_date ?: now()->startOfMonth()->toDateString(),
            $this->end_date ?: now()->toDateString(),
        );
    }

    /** @return array{debit: float, credit: float, balanced: bool, difference: float} */
    public function getTotals(): array
    {
        return JournalMapping::totals($this->getRows());
    }

    public function getExportUrl(): string
    {
        return route('export.journal', array_filter([
            'branch_id' => $this->branch_id,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
        ]));
    }
}
