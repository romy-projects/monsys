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

    /** D6 (2026-11-20): a settlement is settled by the next commercial document. */
    public const STATUS_OPEN    = 'open';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_VOID    = 'void';

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
        'settled_by_type',
        'settled_by_id',
        'settled_at',
        'settled_by_user_id',
        'notes',
    ];

    protected $casts = [
        'quantity'                => 'integer',
        'unit_price'              => 'decimal:2',
        'amount'                  => 'decimal:2',
        'applies_to_next_delivery' => 'boolean',
        'settled_at'              => 'datetime',
    ];

    /** Document types a settlement can be settled by (D6). */
    public const DOCUMENT_INVOICE       = 'invoice';
    public const DOCUMENT_SALES_ORDER   = 'sales_order';
    public const DOCUMENT_LOADING_ORDER = 'loading_order';

    /** @return array<string, string> */
    public static function documents(): array
    {
        return [
            self::DOCUMENT_INVOICE       => 'Invoice',
            self::DOCUMENT_SALES_ORDER   => 'Sales Order (SO)',
            self::DOCUMENT_LOADING_ORDER => 'Loading Order (LOT/LOE)',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_OPEN    => 'Open',
            self::STATUS_SETTLED => 'Settled',
            self::STATUS_VOID    => 'Void',
        ];
    }

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

    /** Who marked the obligation settled/void. */
    public function settledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by_user_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * D6: the document that carried this obligation never becomes a receivable or
     * payable row — it is the next Invoice / SO / LOT / LOE of the branch.
     */
    public function settledDocument(): ?Model
    {
        if ($this->settled_by_type === null || $this->settled_by_id === null) {
            return null;
        }

        return match ($this->settled_by_type) {
            self::DOCUMENT_INVOICE       => Invoice::find($this->settled_by_id),
            self::DOCUMENT_SALES_ORDER,
            self::DOCUMENT_LOADING_ORDER => DeliveryOrder::find($this->settled_by_id),
            default                      => null,
        };
    }

    public function getSettledDocumentLabelAttribute(): ?string
    {
        if ($this->settled_by_type === null) {
            return null;
        }

        $label = self::documents()[$this->settled_by_type] ?? $this->settled_by_type;
        $document = $this->settledDocument();
        $number = $document?->invoice_number ?? $document?->do_number ?? $this->settled_by_id;

        return trim($label.' '.$number);
    }

    /**
     * Mark the obligation settled because the given document carried it.
     * Returns false when the row is no longer open (nothing is overwritten).
     */
    public function markSettled(string $documentType, int $documentId, ?int $userId = null): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        $this->forceFill([
            'status'              => self::STATUS_SETTLED,
            'settled_by_type'     => $documentType,
            'settled_by_id'       => $documentId,
            'settled_at'          => now(),
            'settled_by_user_id'  => $userId,
        ])->save();

        return true;
    }

    /** Void an obligation the admin decided not to charge (audit trail kept). */
    public function markVoid(?int $userId = null, ?string $reason = null): bool
    {
        if ($this->status === self::STATUS_VOID) {
            return false;
        }

        $note = filled($reason) ? 'Void: '.trim($reason) : 'Void.';

        $this->forceFill([
            'status'             => self::STATUS_VOID,
            'settled_at'         => now(),
            'settled_by_user_id' => $userId,
            'notes'              => trim(((string) $this->notes)."\n".$note),
        ])->save();

        return true;
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
