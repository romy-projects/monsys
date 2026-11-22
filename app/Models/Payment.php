<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 19B: one Kas/Bank transfer, allocated to one or more documents.
 *
 * A payment header is the money movement (date, method, Kas/Bank, branch,
 * reference). `payment_details` says which invoices / receivables / payables the
 * money settled, so a single transfer that pays two invoices is still one bank
 * line and one journal entry (DR Kas once, CR Piutang per allocation).
 *
 * `amount` is always the sum of the details — `PaymentLedger` writes both in one
 * transaction and every consumer may rely on that invariant.
 */
class Payment extends Model
{
    use HasFactory;

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    protected $fillable = [
        'payment_no',
        'direction',
        'paid_on',
        'method',
        'cash_account_id',
        'branch_id',
        'amount',
        'reference_no',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'paid_on' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // payment_details.payment_id cascades in the schema; the hook keeps the
        // delete correct when SQLite foreign keys are switched off.
        static::deleting(function (Payment $payment): void {
            $payment->details()->delete();
        });
    }

    /** How the header amount is split across documents. */
    public function details(): HasMany
    {
        return $this->hasMany(PaymentDetail::class);
    }

    /** Kas/Bank account the money entered (in) or left (out). */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'cash_account_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Payment methods keyed by the value stored in the enum column. */
    public static function methods(): array
    {
        return [
            'cash' => 'Tunai',
            'transfer' => 'Transfer',
            'giro' => 'Giro',
            'other' => 'Lainnya',
        ];
    }

    public function getMethodLabelAttribute(): string
    {
        return self::methods()[$this->method] ?? ucfirst((string) $this->method);
    }

    public function getDirectionLabelAttribute(): string
    {
        return $this->direction === self::DIRECTION_OUT ? 'Uang Keluar' : 'Uang Masuk';
    }

    /** Sum of the allocations, as stored (the invariant, not a recomputation). */
    public function getAllocatedAmountAttribute(): float
    {
        return (float) $this->amount;
    }

    public function isFullyAllocated(): bool
    {
        return abs($this->details()->sum('amount') - (float) $this->amount) < 0.005;
    }

    /** Document numbers this transfer settles, e.g. "INV2026-001, INV2026-002". */
    public function getTargetLabelAttribute(): string
    {
        $labels = $this->details
            ->map(fn (PaymentDetail $detail): ?string => $detail->target_label)
            ->filter()
            ->values();

        return $labels->isEmpty() ? '-' : $labels->implode(', ');
    }

    public function scopeReceived(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_IN);
    }

    public function scopePaidOut(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_OUT);
    }

    /** Payments recorded on/after the given date (cash-flow windows). */
    public function scopePaidBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('paid_on', [$from, $to]);
    }
}
