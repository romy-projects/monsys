<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CylinderCirculation extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'transaction_date',
        'so_number',
        'transaction_type',
        'description',
        'cylinder_type',
        'direction',
        'quantity',
        'container_no',
        'handled_by',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'quantity'         => 'integer',
    ];

    /** Auto-set direction + description on create. */
    protected static function booted(): void
    {
        static::creating(function (self $circulation) {
            // Auto-set direction based on transaction_type
            if (is_null($circulation->direction)) {
                $circulation->direction = match ($circulation->transaction_type) {
                    'kirim'           => 'debit',
                    'bongkar_kosong'  => 'kredit',
                    'pembelian'       => 'debit',
                    'penyesuaian'     => 'debit', // default debit; user can override
                };
            }

            // Auto-fill description if empty
            if (blank($circulation->description)) {
                $typeLabel = match ($circulation->transaction_type) {
                    'kirim'          => 'Pengiriman tabung',
                    'bongkar_kosong' => 'Bongkaran kosong',
                    'pembelian'      => 'Pembelian tabung baru',
                    'penyesuaian'    => 'Penyesuaian stok',
                };
                $circulation->description = "{$typeLabel} {$circulation->cylinder_type} — {$circulation->quantity} pcs";
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
