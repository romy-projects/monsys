<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receivable extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id', 'buyer_name', 'buyer_type',
        'invoice_number', 'invoice_date', 'due_date',
        'amount', 'paid_amount', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date'     => 'date',
        'amount'       => 'decimal:2',
        'paid_amount'  => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Remaining unpaid balance. */
    public function getBalanceAttribute(): float
    {
        return max(0, (float) $this->amount - (float) $this->paid_amount);
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
            $status = 'outstanding';
        }

        $this->status = $status;
        $this->saveQuietly();
    }

    /** Mark any outstanding/partial records whose due_date has passed as overdue. */
    public static function refreshOverdueStatuses(): void
    {
        static::whereIn('status', ['outstanding', 'partial'])
            ->where('due_date', '<', today())
            ->each(fn ($r) => $r->recalculateStatus());
    }
}
