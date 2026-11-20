<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BALI-17: one Transportir payable payment instalment, tied to the LO it settles.
 */
class PayablePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payable_id',
        'delivery_order_id',
        'paid_on',
        'amount',
        'method',
        'reference_no',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'paid_on' => 'date',
        'amount'  => 'decimal:2',
    ];

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    /** The Loading Order (Transportir LO) this payment settles. */
    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Payment methods keyed by the value stored in the enum column. */
    public static function methods(): array
    {
        return ReceivablePayment::methods();
    }

    public function getMethodLabelAttribute(): string
    {
        return self::methods()[$this->method] ?? ucfirst((string) $this->method);
    }
}
