<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use HasFactory, SoftDeletes;

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
    // Scopes
    // =========================================================

    /** Filter only active branches */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
