<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BALI-18…22: reconciliation of one delivered Loading Order against its Purchase Order.
 *
 * Quantity snapshots follow the chain PO (paid) → SO (redeemed) → LO (delivered).
 * Discrepancies are stored on the row and mirrored into ReconciliationSettlement rows.
 */
class CylinderReconciliation extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'loading_order_id',
        'sales_order_id',
        'branch_id',
        'cylinder_type',
        'quantity_paid',
        'quantity_redeemed',
        'quantity_delivered',
        'quantity_unredeemed',
        'quantity_short_delivered',
        'quantity_over_delivered',
        'case_type',
        'unit_price',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity_paid'            => 'integer',
        'quantity_redeemed'        => 'integer',
        'quantity_delivered'       => 'integer',
        'quantity_unredeemed'      => 'integer',
        'quantity_short_delivered' => 'integer',
        'quantity_over_delivered'  => 'integer',
        'unit_price'               => 'decimal:2',
    ];

    // =========================================================
    // Relationships
    // =========================================================

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'purchase_order_id');
    }

    public function loadingOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'loading_order_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'sales_order_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(ReconciliationSettlement::class, 'reconciliation_id');
    }

    // =========================================================
    // Helpers
    // =========================================================

    /** Human label for the discrepancy case. */
    public function getCaseLabelAttribute(): string
    {
        return match ($this->case_type) {
            'case_1' => 'Case 1 — Unredeemed PO',
            'case_2' => 'Case 2 — Short Delivery',
            'case_3' => 'Case 3 — Over Delivery',
            default  => 'Balanced',
        };
    }

    /** Total value of every open obligation on this reconciliation. */
    public function openSettlementAmount(): float
    {
        return (float) $this->settlements
            ->where('status', 'open')
            ->sum(fn (ReconciliationSettlement $settlement) => (float) $settlement->amount);
    }
}
