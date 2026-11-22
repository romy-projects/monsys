<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Support\TrialBalance;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

/**
 * Phase 19B / T19B-03: Neraca Saldo (trial balance) read from the posted journal
 * (docs/lapkeu-gap-analysis.md §6 batch 2).
 *
 * The report proves the ledger, so it is only as fresh as the last
 * `JournalPostingService::sync()` run for the period. Unposted periods show an
 * empty sheet with an explicit hint instead of silently reporting zeros.
 */
class TrialBalanceReport extends Page
{
    protected static string $view = 'filament.pages.trial-balance-report';

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Neraca Saldo / Trial Balance';

    public ?int $branch_id = null;

    public string $start_date = '';

    public string $end_date = '';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.finance_trial_balance');
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

    /** @return array<string, mixed> */
    public function getReport(): array
    {
        return TrialBalance::build(
            $this->branch_id,
            $this->start_date ?: now()->startOfMonth()->toDateString(),
            $this->end_date ?: now()->toDateString(),
        );
    }
}
