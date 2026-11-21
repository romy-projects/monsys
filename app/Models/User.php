<?php

namespace App\Models;

use App\Models\Concerns\HasTwoFactorAuthentication;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasTwoFactorAuthentication, Notifiable;

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if (! Schema::hasColumn('users', 'role_id')) {
                return;
            }

            // An explicit access-role assignment (Phase 17) always wins over the legacy enum.
            if ($user->isDirty('role_id') && $user->role_id !== null) {
                return;
            }

            if (! $user->isDirty('role') && $user->role_id !== null) {
                return;
            }

            if ($user->role === null) {
                return;
            }

            $roleId = Role::query()->where('name', $user->role)->value('id');

            if ($roleId !== null) {
                $user->role_id = $roleId;
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'role_id',
        'branch_id',
        'phone',
        'status',
        'must_change_password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    // =========================================================
    // Filament Access Control
    // =========================================================

    /**
     * Allow active users to access the Filament panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === 'active';
    }

    /**
     * Whether this account holds the protected Super Admin access role.
     */
    public function isSuperAdmin(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $this->loadMissing('accessRole');

        return $this->accessRole !== null
            && $this->accessRole->is_active
            && $this->accessRole->is_super_admin;
    }

    // =========================================================
    // Role Helpers
    // =========================================================

    /**
     * Whether the account must choose a new password before using the panel.
     */
    public function mustChangePassword(): bool
    {
        return (bool) $this->must_change_password;
    }

    /**
     * The protected Super Admin account is a hidden system account: it never
     * appears in the user list and its password can only be rotated by the
     * account itself.
     */
    public function isProtectedSystemAccount(): bool
    {
        $this->loadMissing('accessRole');

        return (bool) $this->accessRole?->is_super_admin;
    }

    /**
     * Exclude the protected Super Admin account from ordinary user listings.
     */
    public function scopeExcludingSuperAdmins(Builder $query): Builder
    {
        return $query->whereDoesntHave(
            'accessRole',
            fn (Builder $roleQuery) => $roleQuery->where('is_super_admin', true),
        );
    }

    /** @return bool Level 1 — Full access to all branches */
    public function isOwnerPusat(): bool
    {
        return $this->hasPermission('system.business_admin');
    }

    /** @return bool Level 2 — Access to regional branches */
    public function isRegionalLeader(): bool
    {
        return $this->hasPermission('system.regional_access');
    }

    /** @return bool Level 3 — Access to own branch */
    public function isOwnerCabang(): bool
    {
        return $this->hasPermission('system.branch_owner');
    }

    /** @return bool Level 4 — Operational input only, no P&L */
    public function isStaffGudang(): bool
    {
        return $this->hasPermission('system.warehouse_staff');
    }

    /**
     * Whether this account may be impersonated ("Login As") by the given user.
     *
     * The actor needs `impersonation.start`, the target must be active, hold an
     * active access role that is not the protected Super Admin role, and cannot
     * be the actor themselves.
     */
    public function canBeImpersonatedBy(User $actor): bool
    {
        if ($actor->is($this)) {
            return false;
        }

        if (! $actor->hasPermission('impersonation.start')) {
            return false;
        }

        if ($this->status !== 'active') {
            return false;
        }

        $this->loadMissing('accessRole');
        $role = $this->accessRole;

        return $role !== null
            && $role->is_active
            && ! $role->is_super_admin;
    }

    /**
     * Check if user can see financial/P&L data.
     */
    public function canViewFinance(): bool
    {
        foreach (['invoices.view', 'receivables.view', 'payables.view', 'operational_costs.view'] as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user can approve Delivery Orders.
     */
    public function canApproveOrders(): bool
    {
        foreach (['purchase_orders.approve', 'sales_orders.approve', 'loading_orders.approve'] as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user can create Sales Orders (SO) — Main Branch only.
     */
    public function canCreateSalesOrder(): bool
    {
        return $this->hasPermission('sales_orders.create');
    }

    /**
     * Check if user can create Loading Orders (LO) — Main Branch only.
     */
    public function canCreateLoadingOrder(): bool
    {
        return $this->hasPermission('loading_orders.create');
    }

    /**
     * Check if user can create Purchase Orders (PO) — Other Branches only.
     */
    public function canCreatePurchaseOrder(): bool
    {
        return $this->hasPermission('purchase_orders.create');
    }

    // =========================================================
    // Relationships
    // =========================================================

    /**
     * The branch this user belongs to.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function accessRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function assignedBranches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branch');
    }

    public function hasPermission(string $permissionKey): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $this->loadMissing('accessRole.permissions');
        $role = $this->accessRole;

        if ($role === null || ! $role->is_active) {
            return false;
        }

        if ($role->is_super_admin) {
            return Permission::query()->where('key', $permissionKey)->exists();
        }

        return $role->permissions->contains('key', $permissionKey);
    }

    public function canAccessBranch(int $branchId): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $this->loadMissing('accessRole');
        $role = $this->accessRole;

        if ($role === null || ! $role->is_active) {
            return false;
        }

        if ($role->is_super_admin || $role->data_scope === Role::SCOPE_ALL_BRANCHES) {
            return true;
        }

        if ($role->data_scope === Role::SCOPE_OWN_BRANCH) {
            return $this->branch_id !== null && (int) $this->branch_id === $branchId;
        }

        if ($role->data_scope === Role::SCOPE_ASSIGNED_BRANCHES) {
            return $this->assignedBranches()->where('branches.id', $branchId)->exists();
        }

        return false;
    }

    public function canAccessAllBranches(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $this->loadMissing('accessRole');
        $role = $this->accessRole;

        return $role !== null
            && $role->is_active
            && ($role->is_super_admin || $role->data_scope === Role::SCOPE_ALL_BRANCHES);
    }

    public function canAccessModelRecord(Model $model, string $action): bool
    {
        if ($model instanceof Branch) {
            return $model->exists && $this->canAccessBranch((int) $model->getKey());
        }

        if ($model instanceof DeliveryOrder) {
            $branchIds = array_filter([
                $model->origin_branch_id === null ? null : (int) $model->origin_branch_id,
                $model->destination_branch_id === null ? null : (int) $model->destination_branch_id,
            ]);

            foreach ($branchIds as $branchId) {
                if ($this->canAccessBranch($branchId)) {
                    return true;
                }
            }

            return false;
        }

        if (! array_key_exists('branch_id', $model->getAttributes())) {
            return true;
        }

        if ($model->getAttribute('branch_id') !== null) {
            return $this->canAccessBranch((int) $model->getAttribute('branch_id'));
        }

        return $action === 'view'
            && method_exists($model, 'includesUnassignedBranchRecords')
            && $model->includesUnassignedBranchRecords();
    }

    public function accessibleBranchIds(): array
    {
        if ($this->status !== 'active') {
            return [];
        }

        $this->loadMissing('accessRole');
        $role = $this->accessRole;

        if ($role === null || ! $role->is_active) {
            return [];
        }

        if ($role->is_super_admin || $role->data_scope === Role::SCOPE_ALL_BRANCHES) {
            return Branch::query()->pluck('id')->all();
        }

        if ($role->data_scope === Role::SCOPE_OWN_BRANCH) {
            return $this->branch_id === null ? [] : [(int) $this->branch_id];
        }

        if ($role->data_scope === Role::SCOPE_ASSIGNED_BRANCHES) {
            return $this->assignedBranches()->pluck('branches.id')->all();
        }

        return [];
    }

    public function applyAccessibleBranchScope(
        Builder $query,
        string $branchColumn = 'branch_id',
        bool $includeUnassigned = false
    ): Builder {
        if ($this->status !== 'active') {
            return $query->whereIn($branchColumn, []);
        }

        $this->loadMissing('accessRole');
        $role = $this->accessRole;

        if ($role === null || ! $role->is_active) {
            return $query->whereIn($branchColumn, []);
        }

        if ($role->is_super_admin || $role->data_scope === Role::SCOPE_ALL_BRANCHES) {
            return $query;
        }

        if ($role->data_scope === Role::SCOPE_OWN_BRANCH) {
            return $this->applyBranchIdsScope(
                $query,
                $branchColumn,
                $this->branch_id === null ? [] : [(int) $this->branch_id],
                $includeUnassigned
            );
        }

        if ($role->data_scope === Role::SCOPE_ASSIGNED_BRANCHES) {
            $branchIds = $this->assignedBranches()->pluck('branches.id');

            return $this->applyBranchIdsScope($query, $branchColumn, $branchIds->all(), $includeUnassigned);
        }

        return $query->whereIn($branchColumn, []);
    }

    private function applyBranchIdsScope(
        Builder $query,
        string $branchColumn,
        array $branchIds,
        bool $includeUnassigned
    ): Builder {
        return $query->where(function (Builder $branchQuery) use ($branchColumn, $branchIds, $includeUnassigned): void {
            $branchQuery->whereIn($branchColumn, $branchIds);

            if ($includeUnassigned) {
                $branchQuery->orWhereNull($branchColumn);
            }
        });
    }
}
