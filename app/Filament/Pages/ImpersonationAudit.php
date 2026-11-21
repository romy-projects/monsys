<?php

namespace App\Filament\Pages;

use App\Models\AuditLog;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\WithPagination;

/**
 * Phase 17 / T17-06 — read-only impersonation & access audit trail.
 *
 * Visible to the same accounts that may impersonate (super-admin exclusive).
 */
class ImpersonationAudit extends Page
{
    use WithPagination;

    protected static string $view = 'filament.pages.impersonation-audit';

    protected static ?string $navigationIcon = null;

    protected static ?int $navigationSort = 91;

    protected static ?string $title = 'Impersonation Audit Trail';

    public ?string $search = null;

    public ?string $event = null;

    public ?string $date_from = null;

    public ?string $date_until = null;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.access');
    }

    public static function getNavigationLabel(): string
    {
        return __('nav.item.impersonation_audit');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasPermission('impersonation.start');
    }

    /** @return array<string, string> */
    public static function eventOptions(): array
    {
        return [
            'impersonation.started' => 'Impersonation started',
            'impersonation.stopped' => 'Impersonation stopped',
            'impersonation.action' => 'Action while impersonating',
            'user.access_role_changed' => 'User access role changed',
            'role.created' => 'Role created',
            'role.updated' => 'Role updated',
        ];
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function getLogs(): LengthAwarePaginator
    {
        return AuditLog::query()
            ->with(['user:id,name', 'impersonator:id,name'])
            ->when($this->event, fn ($query) => $query->where('event', $this->event))
            ->when($this->search, function ($query): void {
                $term = '%'.$this->search.'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('description', 'like', $term)
                        ->orWhere('ip_address', 'like', $term)
                        ->orWhereHas('user', fn ($query) => $query->where('name', 'like', $term))
                        ->orWhereHas('impersonator', fn ($query) => $query->where('name', 'like', $term));
                });
            })
            ->when($this->date_from, fn ($query) => $query->whereDate('created_at', '>=', $this->date_from))
            ->when($this->date_until, fn ($query) => $query->whereDate('created_at', '<=', $this->date_until))
            ->latest('id')
            ->paginate(25);
    }
}
