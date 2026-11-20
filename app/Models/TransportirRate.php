<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Fixed haulage rate charged by a transportir per cylinder type (BALI-10).
 *
 * Rates are effective-dated: the rate for a transaction is the newest row whose
 * effective_date is on or before the transaction date.
 */
class TransportirRate extends Model
{
    use HasFactory;

    public const CYLINDER_TYPES = ['3kg', '5.5kg', '12kg', '50kg'];

    protected $fillable = [
        'transportir_id',
        'cylinder_type',
        'rate',
        'effective_date',
        'created_by',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'rate'           => 'decimal:2',
    ];

    public function transportir(): BelongsTo
    {
        return $this->belongsTo(Transportir::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The rate for a transportir + cylinder type effective at a given date.
     */
    public static function rateAtDate(int $transportirId, string $cylinderType, ?string $date = null): ?self
    {
        $date = $date ?: today()->toDateString();

        return static::where('transportir_id', $transportirId)
            ->where('cylinder_type', $cylinderType)
            ->whereDate('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Rate amount (Rp per cylinder) effective at a date, or 0 when not configured.
     */
    public static function amountAtDate(int $transportirId, string $cylinderType, ?string $date = null): float
    {
        return (float) (static::rateAtDate($transportirId, $cylinderType, $date)?->rate ?? 0);
    }

    public static function requiredRateAtDate(int $transportirId, string $cylinderType, ?string $date = null): self
    {
        $date = $date ?: today()->toDateString();

        return static::rateAtDate($transportirId, $cylinderType, $date)
            ?? throw ValidationException::withMessages([
                'transportir_id' => "No Transportir Rate is configured for cylinder type {$cylinderType} on {$date}. Add the rate in Master Data before saving this transaction.",
            ]);
    }

    /**
     * All current rates for a transportir, keyed by cylinder type.
     *
     * @return Collection<string, float>
     */
    public static function ratesFor(int $transportirId, ?string $date = null): Collection
    {
        $date = $date ?: today()->toDateString();

        return static::where('transportir_id', $transportirId)
            ->whereDate('effective_date', '<=', $date)
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get()
            ->groupBy('cylinder_type')
            ->map(fn (Collection $rows) => (float) $rows->last()->rate);
    }
}
