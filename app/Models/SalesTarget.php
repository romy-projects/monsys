<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesTarget extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id', 'year', 'month', 'cylinder_type',
        'target_qty', 'target_revenue', 'created_by',
    ];

    protected $casts = [
        'year'           => 'integer',
        'month'          => 'integer',
        'target_qty'     => 'integer',
        'target_revenue' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Total target for a branch in a given year/month, keyed by cylinder_type. */
    public static function forMonth(int $branchId, int $year, int $month): \Illuminate\Support\Collection
    {
        return static::where('branch_id', $branchId)
            ->where('year', $year)
            ->where('month', $month)
            ->get()
            ->keyBy('cylinder_type');
    }

    /** Sum target_revenue for a branch across a list of year/month pairs. */
    public static function revenueForPeriod(int $branchId, array $yearMonths): float
    {
        $query = static::where('branch_id', $branchId);

        $query->where(function ($q) use ($yearMonths) {
            foreach ($yearMonths as $ym) {
                $q->orWhere(fn ($q2) => $q2->where('year', $ym['year'])->where('month', $ym['month']));
            }
        });

        return (float) $query->sum('target_revenue');
    }
}
