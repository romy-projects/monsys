<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expedition extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'code', 'phone', 'contact_person', 'status',
    ];

    public function deliveryOrders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DeliveryOrder::class);
    }
}
