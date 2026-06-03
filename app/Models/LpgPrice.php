<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LpgPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'cylinder_type',
        'purchase_price',
        'selling_price',
        'effective_date',
        'created_by',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'purchase_price' => 'decimal:2',
        'selling_price'  => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the current price for a cylinder type, optionally for a specific branch.
     * Branch-specific prices override global (null branch_id) prices.
     */
    public static function currentPrice(string $cylinderType, ?int $branchId = null): ?self
    {
        // Try branch-specific price first
        if ($branchId) {
            $branchPrice = static::where('cylinder_type', $cylinderType)
                ->where('branch_id', $branchId)
                ->where('effective_date', '<=', today())
                ->latest('effective_date')
                ->first();

            if ($branchPrice) {
                return $branchPrice;
            }
        }

        // Fallback to global price
        return static::where('cylinder_type', $cylinderType)
            ->whereNull('branch_id')
            ->where('effective_date', '<=', today())
            ->latest('effective_date')
            ->first();
    }
}
