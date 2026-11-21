<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payable extends Model
{
    use HasFactory;

    protected $fillable = [
        'transportir_id',
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
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_date' => 'date',
        'paid_at' => 'datetime',
    ];

    public function transportir(): BelongsTo
    {
        return $this->belongsTo(Transportir::class);
    }

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    /** BALI-17: every payment instalment recorded against this payable. */
    public function payments(): HasMany
    {
        return $this->hasMany(PayablePayment::class);
    }

    /**
     * BALI-17: recompute paid_amount / status / paid_at from the payment ledger
     * so the header total can never drift from the payment history.
     */
    public function syncPaidFromLedger(): void
    {
        $this->paid_amount = (float) $this->payments()->sum('amount');

        $fullyPaid = $this->balance <= 0;

        $this->status = $fullyPaid ? 'paid' : 'pending';
        $this->paid_at = $fullyPaid ? ($this->paid_at ?? now()) : null;
        $this->saveQuietly();
    }

    /**
     * Derived three-state label for the UI. The payable_status enum only knows
     * pending/paid, so "partial" is never stored.
     */
    public function getPaymentStateAttribute(): string
    {
        if ($this->status === 'paid' || $this->balance <= 0) {
            return 'paid';
        }

        return (float) $this->paid_amount > 0 ? 'partial' : 'pending';
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
