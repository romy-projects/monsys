<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 19B / T19B-01: one posted journal entry (a balanced set of lines).
 *
 * The operational tables stay the source of truth for quantities and prices;
 * this table is the accounting record of them. Re-posting never mutates a live
 * entry — the previous revision is voided and a new revision is posted, so a
 * report that was already handed out can always be reproduced.
 */
class JournalEntry extends Model
{
    use HasFactory;

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOID = 'void';

    /** Journal event names shared with App\Support\JournalMapping. */
    public const EVENT_SALES = 'sales';

    public const EVENT_HPP = 'hpp';

    public const EVENT_TRANSPORT = 'transport';

    public const EVENT_PAYMENT = 'payment';

    public const EVENT_EXPENSE = 'expense';

    public const EVENT_SETTLEMENT = 'settlement';

    /** T19B-10 receipt half: stock arriving from Pertamina. */
    public const EVENT_RECEIPT = 'receipt';

    /** T19B-14: verified stock-opname difference on `5-131`. */
    public const EVENT_OPNAME = 'opname';

    public const EVENT_OPENING = 'opening';

    protected $fillable = [
        'entry_no',
        'entry_date',
        'description',
        'source_type',
        'source_id',
        'event',
        'branch_id',
        'revision',
        'status',
        'posted_at',
        'posted_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
        'revision' => 'integer',
        'source_id' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function scopeVoid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VOID);
    }

    public function scopeForPeriod(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereDate('entry_date', '>=', $startDate)
            ->whereDate('entry_date', '<=', $endDate);
    }

    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        return $branchId ? $query->where('branch_id', $branchId) : $query;
    }

    public function scopeForSource(Builder $query, string $sourceType, ?int $sourceId, string $event): Builder
    {
        return $query->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('event', $event);
    }

    /** Grouping key shared with the posting service: "invoice:12:sales". */
    public function sourceKey(): string
    {
        return $this->source_type.':'.$this->source_id.':'.$this->event;
    }

    public function getTotalDebitAttribute(): float
    {
        return round((float) $this->lines->sum('debit'), 2);
    }

    public function getTotalCreditAttribute(): float
    {
        return round((float) $this->lines->sum('credit'), 2);
    }

    public function getIsBalancedAttribute(): bool
    {
        return abs($this->total_debit - $this->total_credit) < 0.005;
    }

    public function getIsVoidAttribute(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    /**
     * Content hash of an entry (date, branch and every line). The posting
     * service re-posts only when this changes, so a second sync of an unchanged
     * period is free.
     */
    public function fingerprint(): string
    {
        return self::fingerprintFor(
            $this->entry_date?->toDateString(),
            $this->branch_id === null ? null : (int) $this->branch_id,
            $this->lines
                ->map(static fn (JournalEntryLine $line): array => [
                    'account_code' => (string) ($line->account?->code ?? $line->chart_of_account_id),
                    'description' => (string) $line->description,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                    'is_intercompany' => (bool) $line->is_intercompany,
                ])
                ->values()
                ->all()
        );
    }

    /**
     * The single definition of an entry's content hash, shared with
     * App\Services\JournalPostingService (which compares before it posts).
     *
     * @param  array<int, array{account_code: string, description?: string|null, debit: float, credit: float, is_intercompany?: bool}>  $lines
     */
    public static function fingerprintFor(?string $date, ?int $branchId, array $lines): string
    {
        $normalised = array_map(static fn (array $line): array => [
            'code' => (string) $line['account_code'],
            'debit' => number_format((float) $line['debit'], 2, '.', ''),
            'credit' => number_format((float) $line['credit'], 2, '.', ''),
            'description' => (string) ($line['description'] ?? ''),
            'intercompany' => (bool) ($line['is_intercompany'] ?? false),
        ], $lines);

        usort($normalised, static fn (array $a, array $b): int => [$a['code'], $a['debit'], $a['credit']] <=> [$b['code'], $b['debit'], $b['credit']]);

        return md5(json_encode([
            'date' => $date,
            'branch' => $branchId,
            'lines' => $normalised,
        ]) ?: '');
    }
}
