<?php

namespace App\Models;

use App\Models\Concerns\ScopesToAccessibleBranches;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationalCost extends Model
{
    use HasFactory, ScopesToAccessibleBranches;

    protected $fillable = [
        'branch_id', 'cost_category', 'description',
        'amount', 'cost_date', 'notes', 'created_by',
    ];

    protected $casts = ['cost_date' => 'date'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
