<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LpgPrice extends Model
{
    use HasFactory;

    protected $fillable = [
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function currentPrice(string $cylinderType): ?self
    {
        return static::where('cylinder_type', $cylinderType)
            ->where('effective_date', '<=', today())
            ->latest('effective_date')
            ->first();
    }
}
