<?php

namespace App\Models;

use App\Models\Concerns\ScopesToAccessibleBranches;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockItem extends Model
{
    use HasFactory, ScopesToAccessibleBranches;

    protected $fillable = [
        'branch_id', 'cylinder_type',
        'qty_full', 'qty_empty', 'qty_damaged', 'recorded_at',
    ];

    protected $casts = ['recorded_at' => 'date'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
