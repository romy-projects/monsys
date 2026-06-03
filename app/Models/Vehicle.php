<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use HasFactory;

    protected $fillable = [
        'expedition_id',
        'plate_number',
        'type',
        'driver_name',
        'driver_phone',
        'capacity_kg',
        'status',
        'notes',
    ];

    protected $casts = [
        'capacity_kg' => 'decimal:2',
    ];

    public function expedition(): BelongsTo
    {
        return $this->belongsTo(Expedition::class);
    }

    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class);
    }

    public function scopeActive($query): void
    {
        $query->where('status', 'active');
    }
}
