<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 19B / T19B-01: one debit/credit line of a journal entry.
 */
class JournalEntryLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_entry_id',
        'chart_of_account_id',
        'description',
        'debit',
        'credit',
        'is_intercompany',
        'sort_order',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'is_intercompany' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_of_account_id');
    }

    /** Signed movement in the account's normal-balance direction. */
    public function getSignedAmountAttribute(): float
    {
        $normal = (string) ($this->account?->saldo_normal ?? 'D');

        return $normal === 'K'
            ? round((float) $this->credit - (float) $this->debit, 2)
            : round((float) $this->debit - (float) $this->credit, 2);
    }
}
