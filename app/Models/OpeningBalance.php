<?php

namespace App\Models;

use App\Models\Concerns\ScopesToAccessibleBranches;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 19B / T19B-12: one account balance carried over from the previous book.
 *
 * `amount` is always stated in the account's normal direction (a debit for
 * `1-111 Kas`, a credit for `2-111 Hutang Usaha`), matching the `NS` sheet of
 * the client workbook. `JournalMapping` posts a branch's rows as one balanced
 * `Saldo Awal` entry, so a branch should carry a single `as_of_date`.
 */
class OpeningBalance extends Model
{
    use HasFactory, ScopesToAccessibleBranches;

    protected $fillable = [
        'branch_id',
        'chart_of_account_id',
        'as_of_date',
        'amount',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'as_of_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        return $branchId ? $query->where('branch_id', $branchId) : $query;
    }

    public function scopeUpTo(Builder $query, string $date): Builder
    {
        return $query->whereDate('as_of_date', '<=', $date);
    }
}
