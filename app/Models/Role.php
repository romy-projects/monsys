<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    public const SCOPE_ALL_BRANCHES = 'all_branches';

    public const SCOPE_ASSIGNED_BRANCHES = 'assigned_branches';

    public const SCOPE_OWN_BRANCH = 'own_branch';

    /**
     * Capability permission every built-in role must keep so the User role
     * helpers (isOwnerPusat, isRegionalLeader, ...) stay truthful.
     *
     * @var array<string, string>
     */
    public const CAPABILITY_PERMISSIONS = [
        'owner_pusat' => 'system.business_admin',
        'regional_leader' => 'system.regional_access',
        'owner_cabang' => 'system.branch_owner',
        'staff_gudang' => 'system.warehouse_staff',
    ];

    protected $attributes = [
        'data_scope' => self::SCOPE_OWN_BRANCH,
        'is_system' => false,
        'is_super_admin' => false,
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'label',
        'description',
        'data_scope',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::updating(function (Role $role): void {
            // The identifier of a built-in role is referenced by code (and by the
            // legacy users.role enum), so it can never be renamed.
            if ($role->getOriginal('is_system') && $role->isDirty('name')) {
                $role->name = (string) $role->getOriginal('name');
            }

            // The protected Super Admin role must stay active, global and protected.
            if ($role->getOriginal('is_super_admin')) {
                $role->is_system = true;
                $role->is_super_admin = true;
                $role->is_active = true;
                $role->data_scope = self::SCOPE_ALL_BRANCHES;
            }
        });

        // Built-in roles can never be deleted; they are referenced by migrations,
        // the legacy enum and the User capability helpers.
        static::deleting(fn (Role $role): bool => ! $role->is_system);
    }

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    // =========================================================
    // Query Scopes
    // =========================================================

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Roles that can be assigned to a user from the UI (Super Admin is promoted
     * only through `access:bootstrap-super-admin`).
     */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('is_super_admin', false);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('is_super_admin')
            ->orderBy('name');
    }

    // =========================================================
    // Helpers
    // =========================================================

    /** @return array<string, string> */
    public static function dataScopeOptions(): array
    {
        return [
            self::SCOPE_ALL_BRANCHES => 'All branches',
            self::SCOPE_ASSIGNED_BRANCHES => 'Assigned branches',
            self::SCOPE_OWN_BRANCH => 'Own branch',
        ];
    }

    public function getScopeLabelAttribute(): string
    {
        return self::dataScopeOptions()[$this->data_scope] ?? (string) $this->data_scope;
    }

    public function isAssignable(): bool
    {
        return $this->is_active && ! $this->is_super_admin;
    }

    /**
     * Capability permission this built-in role must keep, or null for custom roles.
     */
    public function requiredCapabilityKey(): ?string
    {
        if (! $this->is_system) {
            return null;
        }

        return self::CAPABILITY_PERMISSIONS[$this->name] ?? null;
    }

    public function canBeDeleted(): bool
    {
        return ! $this->is_system && $this->users()->doesntExist();
    }
}
