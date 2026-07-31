<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payable extends Model
{
    use HasFactory;

    protected $fillable = [
        'expedition_id',
        'delivery_order_id',
        'invoice_number',
        'description',
        'amount',
        'paid_amount',
        'due_date',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_date'    => 'date',
        'paid_at'     => 'datetime',
    ];

    public function expedition(): BelongsTo
    {
        return $this->belongsTo(Expedition::class);
    }

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    /** Remaining unpaid balance. */
    public function getBalanceAttribute(): float
    {
        return max(0, (float) $this->amount - (float) $this->paid_amount);
    }

    /** Recalculate and persist status based on paid_amount. */
    public function recalculateStatus(): void
    {
        $this->status = $this->balance <= 0 ? 'paid' : 'pending';
        $this->saveQuietly();
    }
}
