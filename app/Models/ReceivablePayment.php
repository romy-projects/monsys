<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BALI-16: one receivable payment instalment.
 */
class ReceivablePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'receivable_id',
        'paid_on',
        'amount',
        'method',
        'reference_no',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'paid_on' => 'date',
        'amount'  => 'decimal:2',
    ];

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Payment methods keyed by the value stored in the enum column. */
    public static function methods(): array
    {
        return [
            'cash'     => 'Tunai',
            'transfer' => 'Transfer',
            'giro'     => 'Giro',
            'other'    => 'Lainnya',
        ];
    }

    public function getMethodLabelAttribute(): string
    {
        return self::methods()[$this->method] ?? ucfirst((string) $this->method);
    }
}
