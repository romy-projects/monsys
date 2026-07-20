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
     * Get the price effective at a given date for a cylinder type.
     *
     * @param  string      $cylinderType
     * @param  string|null $date         Date string (Y-m-d). Defaults to today.
     * @param  int|null    $branchId     Branch-specific price, falls back to global.
     * @return self|null
     */
    public static function priceAtDate(string $cylinderType, ?string $date = null, ?int $branchId = null): ?self
    {
        $date = $date ?: today()->toDateString();

        // Try branch-specific price first
        if ($branchId) {
            $branchPrice = static::where('cylinder_type', $cylinderType)
                ->where('branch_id', $branchId)
                ->where('effective_date', '<=', $date)
                ->latest('effective_date')
                ->first();

            if ($branchPrice) {
                return $branchPrice;
            }
        }

        // Fallback to global price
        return static::where('cylinder_type', $cylinderType)
            ->whereNull('branch_id')
            ->where('effective_date', '<=', $date)
            ->latest('effective_date')
            ->first();
    }

    /**
     * Get the current price for a cylinder type, optionally for a specific branch.
     * Branch-specific prices override global (null branch_id) prices.
     *
     * @deprecated Use priceAtDate() with explicit date instead.
     */
    public static function currentPrice(string $cylinderType, ?int $branchId = null): ?self
    {
        return static::priceAtDate($cylinderType, today()->toDateString(), $branchId);
    }

    /**
     * Calculate total HPP (purchase_price × qty) for a set of sales,
     * matching each sale to the price effective on its sale_date.
     *
     * @param  iterable  $sales  Collection of objects with ->cylinder_type, ->sale_date, ->quantity
     * @param  int|null  $branchId
     * @return float     Total HPP
     */
    public static function totalHppForSales(iterable $sales, ?int $branchId = null): float
    {
        // Pre-load all price records for the branch and types to avoid N+1
        $types = collect($sales)->pluck('cylinder_type')->unique()->values()->all();

        $allPrices = static::whereNull('branch_id')
            ->whereIn('cylinder_type', $types)
            ->orderBy('cylinder_type')
            ->orderBy('effective_date')
            ->get();

        if ($branchId) {
            $branchPrices = static::where('branch_id', $branchId)
                ->whereIn('cylinder_type', $types)
                ->orderBy('cylinder_type')
                ->orderBy('effective_date')
                ->get();
        } else {
            $branchPrices = collect();
        }

        $total = 0.0;

        foreach ($sales as $sale) {
            $type     = $sale->cylinder_type;
            $saleDate = $sale instanceof \stdClass
                ? $sale->sale_date
                : $sale->sale_date->toDateString();
            $qty      = (int) ($sale->quantity ?? $sale->qty ?? 0);

            if ($qty <= 0) {
                continue;
            }

            // Find branch-specific price effective on this sale date
            $price = $branchPrices
                ->where('cylinder_type', $type)
                ->where('effective_date', '<=', $saleDate)
                ->last();

            // Fallback to global
            if (! $price) {
                $price = $allPrices
                    ->where('cylinder_type', $type)
                    ->where('effective_date', '<=', $saleDate)
                    ->last();
            }

            $hpp = $price ? (float) $price->purchase_price : 0;
            $total += $hpp * $qty;
        }

        return $total;
    }
}