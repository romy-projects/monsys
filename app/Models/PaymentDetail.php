<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 19B: one allocation of a payment header to a document.
 *
 * Exactly one of `invoice_id` / `payable_id` / `receivable_id` is set (enforced
 * by `PaymentLedger`); `delivery_order_id` is the LO the payable belongs to, kept
 * on the detail so a multi-LO payable payment still traces each instalment.
 *
 * Date, method, Kas/Bank, reference and the recorder live on the header, but the
 * detail still exposes them (delegated accessors + `$appends`) so the existing
 * report/export/UI code that used `InvoicePayment` keeps working unchanged.
 */
class PaymentDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'invoice_id',
        'payable_id',
        'receivable_id',
        'delivery_order_id',
        'amount',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /** Keep the legacy payment row shape when serialised (API responses). */
    protected $appends = [
        'paid_on',
        'method',
        'method_label',
        'reference_no',
        'cash_account_id',
        'created_by',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    /** The Loading Order this instalment settles (Transportir payables). */
    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    /**
     * Kas/Bank of the transfer this allocation belongs to.
     *
     * The column lives on the header, but `cash_account_id` is exposed on the
     * detail as a delegating accessor, so a normal belongsTo still resolves —
     * and `with('cashAccount')` keeps working on a detail query.
     */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'cash_account_id');
    }

    public function getPaidOnAttribute(): mixed
    {
        return $this->payment?->paid_on;
    }

    public function getMethodAttribute(): ?string
    {
        return $this->payment?->method;
    }

    public function getMethodLabelAttribute(): string
    {
        $method = $this->method;

        return $method === null
            ? '-'
            : (Payment::methods()[$method] ?? ucfirst($method));
    }

    public function getReferenceNoAttribute(): ?string
    {
        return $this->payment?->reference_no;
    }

    public function getCashAccountIdAttribute(): ?int
    {
        return $this->payment?->cash_account_id;
    }

    /**
     * Recorder of the transfer, delegated from the header.
     *
     * Deliberately an accessor instead of a `createdBy()` relation: Eloquent
     * resolves `$detail->createdBy` through this same mutator (get-mutators are
     * matched case-insensitively via Str::studly), so a relation of that name
     * would be shadowed. Callers that need the user object go through
     * `$detail->payment?->createdBy`.
     */
    public function getCreatedByAttribute(): ?int
    {
        return $this->payment?->created_by;
    }

    /** Human label of the document this allocation settles. */
    public function getTargetLabelAttribute(): string
    {
        if ($this->invoice_id !== null) {
            return $this->invoice?->invoice_number ?? 'INV#'.$this->invoice_id;
        }

        if ($this->receivable_id !== null) {
            return $this->receivable?->invoice_number ?? 'AR#'.$this->receivable_id;
        }

        if ($this->payable_id !== null) {
            return $this->payable?->invoice_number
                ?? $this->payable?->description
                ?? 'AP#'.$this->payable_id;
        }

        return $this->deliveryOrder?->do_number ?? '-';
    }

    /** Newest payments first, ordered by the header's paid_on date. */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query
            ->join('payments', 'payments.id', '=', 'payment_details.payment_id')
            ->orderByDesc('payments.paid_on')
            ->orderByDesc('payment_details.id')
            ->select('payment_details.*');
    }
}
