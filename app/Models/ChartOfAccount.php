<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Phase 19A / T19A-01: one account of the merged chart of accounts
 * (see docs/lapkeu-gap-analysis.md §2 for how COA + NS were merged).
 */
class ChartOfAccount extends Model
{
    public const POS_NERACA = 'NR';

    public const POS_LABA_RUGI = 'LR';

    protected $fillable = [
        'code',
        'name',
        'pos',
        'account_group',
        'saldo_normal',
        'is_cash_account',
        'is_active',
        'sort_order',
        'description',
    ];

    protected $casts = [
        'is_cash_account' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function operationalCosts(): HasMany
    {
        return $this->hasMany(OperationalCost::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeCashAccounts(Builder $query): Builder
    {
        return $query->where('is_cash_account', true)->where('is_active', true);
    }

    public function scopeBalanceSheet(Builder $query): Builder
    {
        return $query->where('pos', self::POS_NERACA);
    }

    public function scopeProfitLoss(Builder $query): Builder
    {
        return $query->where('pos', self::POS_LABA_RUGI);
    }

    /** Resolve a code to its account, e.g. ChartOfAccount::code('5-132'). */
    public static function code(string $code): ?self
    {
        return static::query()->where('code', $code)->first();
    }

    /** Account id for a code, or null when the chart has no such account. */
    public static function idFor(string $code): ?int
    {
        return static::query()->where('code', $code)->value('id');
    }

    /** @return array<string, string> */
    public static function groupLabels(): array
    {
        return [
            'asset' => 'Aset / Assets',
            'liability' => 'Kewajiban / Liabilities',
            'equity' => 'Ekuitas / Equity',
            'revenue' => 'Pendapatan / Revenue',
            'cogs' => 'HPP / Cost of Goods Sold',
            'expense' => 'Biaya / Operating Expenses',
        ];
    }

    /** @return array<string, string> */
    public static function posLabels(): array
    {
        return [
            self::POS_NERACA => 'NR — Neraca',
            self::POS_LABA_RUGI => 'LR — Laba Rugi',
        ];
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->code.' — '.$this->name;
    }
}
