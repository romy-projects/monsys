<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'do_number',
        'document_type',
        'counterparty_type',
        'counterparty_name',
        'so_number',
        'po_number',
        'order_type',
        'supplier_name',
        'origin_branch_id',
        'destination_branch_id',
        'expedition_id',
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
        'order_date'    => 'date',
        'eta'           => 'date',
        'loading_date'  => 'date',
        'received_date' => 'date',
        'approved_at'   => 'datetime',
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
}
