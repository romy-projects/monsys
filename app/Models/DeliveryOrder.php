<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'do_number',
        'order_type',
        'supplier_name',
        'origin_branch_id',
        'destination_branch_id',
        'expedition_id',
        'vehicle_id',
        'cylinder_type',
        'quantity_ordered',
        'quantity_received',
        'container_number',
        'order_date',
        'eta',
        'received_date',
        'status',
        'notes',
        'receipt_path',
        'requested_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'order_date'    => 'date',
        'eta'           => 'date',
        'received_date' => 'date',
        'approved_at'   => 'datetime',
    ];

    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'origin_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function expedition(): BelongsTo
    {
        return $this->belongsTo(Expedition::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
