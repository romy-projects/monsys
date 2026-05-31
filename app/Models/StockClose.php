<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockClose extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id', 'close_date', 'cylinder_type',
        'qty_full', 'qty_empty', 'qty_damaged',
        'submitted_by', 'verified_by',
        'submitted_at', 'verified_at',
        'status', 'notes',
    ];

    protected $casts = [
        'close_date'   => 'date',
        'submitted_at' => 'datetime',
        'verified_at'  => 'datetime',
        'qty_full'     => 'integer',
        'qty_empty'    => 'integer',
        'qty_damaged'  => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** True if the branch has at least one submitted/verified close entry for today. */
    public static function isTodaySubmitted(int $branchId): bool
    {
        return static::where('branch_id', $branchId)
            ->where('close_date', today())
            ->whereIn('status', ['submitted', 'verified'])
            ->exists();
    }

    /** Count how many cylinder types have been submitted today for a branch. */
    public static function todaySubmittedCount(int $branchId): int
    {
        return static::where('branch_id', $branchId)
            ->where('close_date', today())
            ->whereIn('status', ['submitted', 'verified'])
            ->count();
    }
}
