<?php

namespace App\Models;

use App\Models\Concerns\ScopesToAccessibleBranches;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalCost extends Model
{
    use HasFactory, ScopesToAccessibleBranches;

    protected $fillable = [
        'branch_id', 'cost_category', 'chart_of_account_id', 'description',
        'qty', 'unit_price', 'amount', 'payment_method', 'cash_account_id',
        'reference_no', 'cost_date', 'notes', 'created_by',
    ];

    protected $casts = [
        'cost_date' => 'date',
        'qty' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Phase 19A / T19A-02: expense account of the merged chart of accounts. */
    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    /** Kas/Bank account the money left from (1-111 / 1-112 / 1-113). */
    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'cash_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
