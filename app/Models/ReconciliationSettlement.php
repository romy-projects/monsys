<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BALI-19…22: one settlement obligation produced by a cylinder reconciliation.
 *
 * Each row belongs to exactly one counterparty (`party`) and carries a distinct
 * `settlement_type`, so a single reconciliation can owe the branch AND claim from
 * the transportir at the same time (Case 2).
 */
class ReconciliationSettlement extends Model
{
    public const PARTY_HQ          = 'hq';
    public const PARTY_BRANCH      = 'branch';
    public const PARTY_TRANSPORTIR = 'transportir';

    public const TYPE_UNREDEEMED_CREDIT   = 'unredeemed_credit';
    public const TYPE_SHORT_DELIVERY_CREDIT = 'short_delivery_credit';
    public const TYPE_TRANSPORTIR_CLAIM   = 'transportir_short_claim';
    public const TYPE_OVER_DELIVERY_CLAIM = 'over_delivery_claim';

    protected $fillable = [
        'reconciliation_id',
        'party',
        'settlement_type',
        'quantity',
        'unit_price',
        'amount',
        'source_purchase_order_id',
        'applies_to_next_delivery',
        'status',
        'notes',
    ];

    protected $casts = [
        'quantity'                => 'integer',
        'unit_price'              => 'decimal:2',
        'amount'                  => 'decimal:2',
        'applies_to_next_delivery' => 'boolean',
    ];

    /** Settlement types with the party they belong to. */
    public static function types(): array
    {
        return [
            self::TYPE_UNREDEEMED_CREDIT     => self::PARTY_HQ,
            self::TYPE_SHORT_DELIVERY_CREDIT => self::PARTY_HQ,
            self::TYPE_TRANSPORTIR_CLAIM     => self::PARTY_TRANSPORTIR,
            self::TYPE_OVER_DELIVERY_CLAIM   => self::PARTY_BRANCH,
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(CylinderReconciliation::class, 'reconciliation_id');
    }

    /** The PO whose unit price was used for this obligation (Case 3 carry-over). */
    public function sourcePurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'source_purchase_order_id');
    }

    public function getTypeLabelAttribute(): string
    {
        return match ($this->settlement_type) {
            self::TYPE_UNREDEEMED_CREDIT     => 'HQ credit to branch (unredeemed PO)',
            self::TYPE_SHORT_DELIVERY_CREDIT => 'HQ credit to branch (short delivery)',
            self::TYPE_TRANSPORTIR_CLAIM     => 'Claim against transportir (short delivery)',
            self::TYPE_OVER_DELIVERY_CLAIM   => 'Claim against branch (over delivery)',
            default                          => $this->settlement_type,
        };
    }
}
