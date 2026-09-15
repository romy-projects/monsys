<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transportir extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'phone',
        'contact_person',
        'status',
    ];

    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function payables(): HasMany
    {
        return $this->hasMany(Payable::class);
    }
}
