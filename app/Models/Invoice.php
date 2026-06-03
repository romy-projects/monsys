<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'invoice_number',
        'customer_id',
        'cylinder_type',
        'quantity',
        'unit_price',
        'total_amount',
        'paid_amount',
        'issue_date',
        'due_date',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'issue_date'   => 'date',
        'due_date'     => 'date',
        'unit_price'   => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount'  => 'decimal:2',
        'quantity'     => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
            ->each(fn($r) => $r->recalculateStatus());
    }
}
