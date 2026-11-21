<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use HasFactory, SoftDeletes;

    /** Code of the head office: the branch the Super Admin is posted at. */
    public const HQ_CODE = 'HQ-001';

    /** Accepted head-office codes, including the shorter form used ad hoc. */
    public const HQ_CODES = [self::HQ_CODE, 'HQ'];

    protected $fillable = [
        'code',
        'name',
        'city',
        'province',
        'address',
        'phone',
        'status',
        'regional_id',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    // =========================================================
    // Relationships
    // =========================================================

    /** Staff and owners assigned to this branch */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Additional branches assigned for roles with assigned-branch scope */
    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_branch');
    }

    /** Sub-branches under this regional branch */
    public function subBranches(): HasMany
    {
        return $this->hasMany(Branch::class, 'regional_id');
    }

    /** Current stock snapshot for this branch */
    public function stockItems(): HasMany
    {
        return $this->hasMany(StockItem::class);
    }

    /** All stock mutations (in/out/transfer) */
    public function stockMutations(): HasMany
    {
        return $this->hasMany(StockMutation::class);
    }

    /** Outgoing delivery orders */
    public function outgoingOrders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class, 'origin_branch_id');
    }

    /** Incoming delivery orders */
    public function incomingOrders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class, 'destination_branch_id');
    }

    /** Daily sales records */
    public function dailySales(): HasMany
    {
        return $this->hasMany(DailySale::class);
    }

    /** Operational cost records */
    public function operationalCosts(): HasMany
    {
        return $this->hasMany(OperationalCost::class);
    }

    // =========================================================
    // Head office
    // =========================================================

    /**
     * The head office, when the installation already has one.
     */
    public static function hq(): ?self
    {
        return static::withTrashed()
            ->whereIn('code', self::HQ_CODES)
            ->orderByRaw('case when code = ? then 0 else 1 end', [self::HQ_CODE])
            ->first();
    }

    /**
     * Provision the head office and return it, active.
     *
     * The Super Admin is a global account, so without a post of its own every
     * branch-scoped screen had no context — the branch dashboard 500'd on a null
     * branch id and new documents defaulted to no origin. HQ is that post, and it
     * is also where `owner_pusat` is seeded, so it doubles as the main branch
     * that purchase/loading orders originate from.
     */
    public static function provisionHq(): self
    {
        $hq = static::hq();

        if ($hq === null) {
            return static::create([
                'code' => self::HQ_CODE,
                'name' => 'Pusat / HQ',
                'city' => 'Jakarta',
                'province' => 'DKI Jakarta',
                'status' => 'active',
            ]);
        }

        // A deactivated or trashed head office would hide the Super Admin's own
        // post from every branch picker, so provisioning brings it back.
        if ($hq->trashed()) {
            $hq->restore();
        }

        if ($hq->status !== 'active') {
            $hq->update(['status' => 'active']);
        }

        return $hq;
    }

    // =========================================================
    // Scopes
    // =========================================================

    /** Filter only active branches */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /** Filter only the main/pusat branch (where owner_pusat users are assigned) */
    public function scopeMainBranch($query)
    {
        return $query->whereHas(
            'users.accessRole.permissions',
            fn ($query) => $query->where('permissions.key', 'system.business_admin')
        );
    }

    /** Check if this branch is the main/pusat branch */
    public function getIsMainAttribute(): bool
    {
        return $this->users()
            ->whereHas(
                'accessRole.permissions',
                fn ($query) => $query->where('permissions.key', 'system.business_admin')
            )
            ->exists();
    }
}
