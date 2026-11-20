<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'do_number',
        'document_type',
        'lo_kind',
        'counterparty_type',
        'counterparty_name',
        'so_number',
        'po_number',
        'sales_order_id',
        'transportir_lo_number',
        'order_type',
        'supplier_name',
        'origin_branch_id',
        'destination_branch_id',
        'transportir_id',
        'expedition_id',
        'cost_borne_by',
        'expedition_cost',
        'expedition_notes',
        'vehicle_id',
        'transportir_name',
        'cylinder_type',
        'quantity_ordered',
        'quantity_received',
        'container_number',
        'order_date',
        'eta',
        'loading_date',
        'loaded_by',
        'received_date',
        'status',
        'shipment_status',
        'notes',
        'receipt_path',
        'requested_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'order_date'      => 'date',
        'eta'             => 'date',
        'loading_date'    => 'date',
        'received_date'   => 'date',
        'approved_at'     => 'datetime',
        'expedition_cost' => 'decimal:2',
    ];

    // =========================================================
    // Scopes — document type discriminators
    // =========================================================

    /** @return Builder Only Sales Orders (SO) */
    public function scopeSalesOrders(Builder $query): Builder
    {
        return $query->where('document_type', 'so');
    }

    /** @return Builder Only Delivery Orders (DO) */
    public function scopeDeliveryOrders(Builder $query): Builder
    {
        return $query->where('document_type', 'do');
    }

    /** @return Builder Only Loading Orders (LO) */
    public function scopeLoadingOrders(Builder $query): Builder
    {
        return $query->where('document_type', 'lo');
    }

    /** @return Builder Loading Orders where the transportir arranges its own expedition (BALI-11) */
    public function scopeTransportirLoadingOrders(Builder $query): Builder
    {
        return $query->where('document_type', 'lo')
            ->where(fn (Builder $q) => $q->where('lo_kind', 'transportir')->orWhereNull('lo_kind'));
    }

    /** @return Builder Loading Orders where HQ/Pusat arranges the expedition (BALI-11) */
    public function scopeExpeditionLoadingOrders(Builder $query): Builder
    {
        return $query->where('document_type', 'lo')->where('lo_kind', 'expedition');
    }

    /** @return Builder Only Purchase Orders (PO) */
    public function scopePurchaseOrders(Builder $query): Builder
    {
        return $query->where('document_type', 'po');
    }

    // =========================================================
    // Relationships
    // =========================================================

    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'origin_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    /**
     * The DO auto-created from this PO (linked via po_number = this do_number).
     * Used to show shipment status inside the PO screen.
     */
    public function linkedDo(): HasOne
    {
        return $this->hasOne(DeliveryOrder::class, 'po_number', 'do_number')
            ->where('document_type', 'do');
    }

    public function lpgPrices(): HasMany
    {
        return $this->hasMany(LpgPrice::class, 'purchase_order_id');
    }

    public function stockMutations(): HasMany
    {
        return $this->hasMany(StockMutation::class, 'purchase_order_id');
    }

    public function cylinderCirculation(): HasOne
    {
        return $this->hasOne(CylinderCirculation::class, 'purchase_order_id');
    }

    public function transportir(): BelongsTo
    {
        return $this->belongsTo(Transportir::class);
    }

    /** Branch-shipping expedition (the one that ships Tabung INTO the Branch). */
    public function expedition(): BelongsTo
    {
        return $this->belongsTo(Expedition::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** User who confirmed the loading (LO only). */
    public function loadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'loaded_by');
    }

    /**
     * The Transportir LO this Expedition LO continues (PO → LO Transportir → LO Expedition).
     * Matched on the document number, same style as linkedDo().
     */
    public function transportirLo(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'transportir_lo_number', 'do_number')
            ->where('document_type', 'lo')
            ->where('lo_kind', 'transportir');
    }

    /** Expedition LOs created against this Transportir LO. */
    public function expeditionLos(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class, 'transportir_lo_number', 'do_number')
            ->where('document_type', 'lo')
            ->where('lo_kind', 'expedition');
    }

    /** The Sales Order this LO/SO row belongs to (BALI-18). */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'sales_order_id');
    }

    /** Reconciliation produced when this LO was delivered (BALI-18). */
    public function reconciliation(): HasOne
    {
        return $this->hasOne(CylinderReconciliation::class, 'loading_order_id');
    }

    // =========================================================
    // Helpers
    // =========================================================

    /** Get the display label for the document type. */
    public function getDocumentTypeLabelAttribute(): string
    {
        return match ($this->document_type) {
            'so' => 'Sales Order',
            'do' => 'Delivery Order',
            'lo' => 'Loading Order',
            'po' => 'Purchase Order',
            default => 'Delivery Order',
        };
    }

    /** Get the display label for the counterparty. */
    public function getCounterpartyLabelAttribute(): ?string
    {
        if ($this->counterparty_type === 'pertamina') {
            return $this->counterparty_name ?: 'Pertamina';
        }

        if ($this->counterparty_type === 'branch') {
            return $this->counterparty_name ?: $this->destinationBranch?->name;
        }

        return null;
    }

    // =========================================================
    // Loading Order kind (BALI-11 / BALI-12 / BALI-13)
    // =========================================================

    /** Whether this is a Transportir LO (transportir arranges its own expedition). */
    public function isTransportirLoadingOrder(): bool
    {
        return $this->document_type === 'lo' && $this->lo_kind !== 'expedition';
    }

    /** Whether this is an Expedition LO (HQ/Pusat arranges the expedition). */
    public function isExpeditionLoadingOrder(): bool
    {
        return $this->document_type === 'lo' && $this->lo_kind === 'expedition';
    }

    /** Get the display label for the Loading Order kind. */
    public function getLoKindLabelAttribute(): ?string
    {
        if ($this->document_type !== 'lo') {
            return null;
        }

        return $this->isExpeditionLoadingOrder() ? 'LO Expedisi' : 'LO Transportir';
    }

    /**
     * Whether HQ/Pusat bears the expedition delivery fee for this LO.
     * A Transportir LO never carries the fee — the transportir handles it itself.
     */
    public function bearsExpeditionCost(): bool
    {
        return $this->isExpeditionLoadingOrder() && $this->cost_borne_by === 'sum';
    }

    /** Delivery fee recorded on this LO (0 when the transportir bears it). */
    public function expeditionFee(): float
    {
        return $this->bearsExpeditionCost() ? (float) ($this->expedition_cost ?? 0) : 0.0;
    }
}
