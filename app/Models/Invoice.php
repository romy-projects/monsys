<?php

namespace App\Models;

use App\Models\Concerns\ScopesToAccessibleBranches;
use App\Support\InvoicePricing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, ScopesToAccessibleBranches, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'invoice_number',
        'customer_id',
        'reference_type',
        'reference_id',
        'lpg_price_id',
        'loading_order_number',
        'cylinder_type',
        'quantity',
        'unit_price',
        'transportir_rate_at_sale',
        'expedition_cost_at_sale',
        'expedition_fee_amount',
        'total_amount',
        'paid_amount',
        'issue_date',
        'due_date',
        'redeemed_on',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'redeemed_on' => 'date',
        'unit_price' => 'decimal:2',
        'transportir_rate_at_sale' => 'decimal:2',
        'expedition_cost_at_sale' => 'decimal:2',
        'expedition_fee_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'quantity' => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Phase 19B / T19B-11 (decision D5): the bill total is always derived from
     * the price inputs, so the Filament create/edit pages, the API and any
     * import can no longer drift apart — EditInvoice used to drop the
     * expedition fee while the API added it.
     *
     * Records without a usable quantity/price (legacy imports, free samples)
     * keep whatever total they were given.
     */
    protected static function booted(): void
    {
        static::saving(function (self $invoice): void {
            if (! $invoice->isDirty(['unit_price', 'quantity', 'expedition_fee_amount'])) {
                return;
            }

            if ((float) $invoice->unit_price <= 0 || (int) $invoice->quantity <= 0) {
                return;
            }

            $invoice->total_amount = InvoicePricing::total([
                'unit_price' => (float) $invoice->unit_price,
                'quantity' => (int) $invoice->quantity,
                'expedition_fee_amount' => (float) ($invoice->expedition_fee_amount ?? 0),
            ]);
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lpgPrice(): BelongsTo
    {
        return $this->belongsTo(LpgPrice::class);
    }

    /** Polymorphic reference: can be a Customer or a Branch. */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /** Get the display name for the reference (customer name or branch name). */
    public function getReferenceNameAttribute(): ?string
    {
        if ($this->reference_type === 'branch' && $this->reference) {
            return $this->reference->name;
        }

        return $this->customer?->name;
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Phase 19B: every allocation of a payment that settled this invoice. */
    public function payments(): HasMany
    {
        return $this->hasMany(PaymentDetail::class);
    }

    /**
     * Derive paid_amount from the payment ledger and persist it together with
     * the status. The ledger is the only source of truth.
     */
    public function syncPaidFromLedger(): void
    {
        $this->paid_amount = (float) $this->payments()->sum('amount');
        $this->recalculateStatus();
    }

    /** Remaining unpaid balance. */
    public function getBalanceAttribute(): float
    {
        return max(0, (float) $this->total_amount - (float) $this->paid_amount);
    }

    /** Recalculate and persist status based on paid_amount and due_date. */
    public function recalculateStatus(): void
    {
        $balance = $this->balance;

        if ($balance <= 0) {
            $status = 'paid';
        } elseif ((float) $this->paid_amount > 0) {
            $status = 'partial';
        } elseif ($this->due_date->lt(today())) {
            $status = 'overdue';
        } else {
            $status = 'issued';
        }

        $this->status = $status;
        $this->saveQuietly();
    }

    /** Mark any issued/partial records whose due_date has passed as overdue. */
    public static function refreshOverdueStatuses(): void
    {
        static::whereIn('status', ['issued', 'partial'])
            ->where('due_date', '<', today())
            ->each(fn ($r) => $r->recalculateStatus());
    }
}
