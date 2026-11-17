<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class LpgPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'purchase_order_id',
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

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'purchase_order_id');
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
                ->whereDate('effective_date', '<=', $date)
                ->orderByDesc('effective_date')
                ->orderByDesc('id')
                ->first();

            if ($branchPrice) {
                return $branchPrice;
            }
        }

        // Fallback to global price
        return static::where('cylinder_type', $cylinderType)
            ->whereNull('branch_id')
            ->whereDate('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();
    }

    public static function requiredPriceAtDate(string $cylinderType, string $date, ?int $branchId = null): self
    {
        return static::priceAtDate($cylinderType, $date, $branchId)
            ?? throw ValidationException::withMessages([
                'lpg_price_id' => "No LPG Price is configured for cylinder type {$cylinderType} on {$date}. Create an effective LPG Price before saving this transaction.",
            ]);
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
     * Calculate HPP from each sale's stored purchase-price snapshot, falling
     * back to date-matched prices for legacy sales without a snapshot.
     *
     * @param  iterable  $sales  Collection of objects with ->cylinder_type, ->sale_date, ->quantity
     * @param  int|null  $branchId
     * @return float     Total HPP
     */
    public static function totalHppForSales(iterable $sales, ?int $branchId = null): float
    {
        $sales = collect($sales);
        $types = $sales->pluck('cylinder_type')->unique()->values()->all();
        $branchIds = $branchId
            ? [$branchId]
            : $sales->pluck('branch_id')->filter()->unique()->values()->all();

        $allPrices = static::whereNull('branch_id')
            ->whereIn('cylinder_type', $types)
            ->orderBy('cylinder_type')
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get();

        $branchPrices = count($branchIds) > 0
            ? static::whereIn('branch_id', $branchIds)
                ->whereIn('cylinder_type', $types)
                ->orderBy('cylinder_type')
                ->orderBy('effective_date')
                ->orderBy('id')
                ->get()
            : collect();

        $referencedPrices = static::whereIn(
            'id',
            $sales->pluck('lpg_price_id')->filter()->unique()->values()
        )->get()->keyBy('id');

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

            if (isset($sale->purchase_price_at_sale) && $sale->purchase_price_at_sale !== null) {
                $total += (float) $sale->purchase_price_at_sale * $qty;
                continue;
            }

            $price = $referencedPrices->get($sale->lpg_price_id ?? null);
            $saleBranchId = $sale->branch_id ?? $branchId;

            if (! $price && $saleBranchId) {
                $price = $branchPrices
                    ->where('branch_id', $saleBranchId)
                    ->where('cylinder_type', $type)
                    ->filter(fn(self $candidate) => $candidate->effective_date->toDateString() <= $saleDate)
                    ->last();
            }

            if (! $price) {
                $price = $allPrices
                    ->where('cylinder_type', $type)
                    ->filter(fn(self $candidate) => $candidate->effective_date->toDateString() <= $saleDate)
                    ->last();
            }

            $hpp = $price ? (float) $price->purchase_price : 0;
            $total += $hpp * $qty;
        }

        return $total;
    }
}