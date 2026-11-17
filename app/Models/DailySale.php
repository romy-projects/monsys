<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailySale extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id', 'customer_id', 'cylinder_type', 'buyer_type',
        'lpg_price_id', 'purchase_price_at_sale',
        'quantity', 'selling_price', 'sale_date',
        'notes', 'created_by',
    ];

    protected $casts = [
        'sale_date' => 'date',
        'purchase_price_at_sale' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lpgPrice(): BelongsTo
    {
        return $this->belongsTo(LpgPrice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
