<?php

namespace App\Support;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 19B / T19B-03: Neraca Saldo (trial balance) built from the posted
 * journal (`journal_entries` + `journal_entry_lines`), not from the operational
 * tables - the ledger is what a Neraca Saldo must prove.
 *
 * Three column pairs per account, exactly like the `NS` sheet of the client
 * workbook: Saldo Awal (opening), Mutasi (movement of the period) and Saldo
 * Akhir (closing). Every pair is presented on the side of the account's normal
 * balance (`saldo_normal`), so an account that ends up on the opposite side
 * shows there instead of being silently flipped.
 *
 * The opening column is every posted line dated before the period start, which
 * includes the `Saldo Awal` entry of T19B-12 - run `JournalPostingService::sync()`
 * for that entry before reading the report.
 */
class TrialBalance
{
    /**
     * @return array{
     *     period: array{start: string, end: string, opening_before: string},
     *     rows: array<int, array<string, mixed>>,
     *     totals: array<string, mixed>
     * }
     */
    public static function build(?int $branchId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?: now()->startOfMonth()->toDateString();
        $endDate = $endDate ?: now()->toDateString();
        $before = Carbon::parse($startDate)->subDay()->toDateString();

        $opening = self::sums($branchId, null, $before);
        $movement = self::sums($branchId, $startDate, $endDate);
        $groupLabels = ChartOfAccount::groupLabels();

        $rows = [];
        $totals = [
            'opening_debit' => 0.0,
            'opening_credit' => 0.0,
            'debit' => 0.0,
            'credit' => 0.0,
            'closing_debit' => 0.0,
            'closing_credit' => 0.0,
        ];

        foreach (ChartOfAccount::query()->orderBy('code')->get() as $account) {
            $code = (string) $account->code;
            $normal = (string) $account->saldo_normal;

            $openingSigned = self::signed($opening[$code] ?? null, $normal);
            $movementSigned = self::signed($movement[$code] ?? null, $normal);
            $closingSigned = round($openingSigned + $movementSigned, 2);

            // Accounts without activity in either window stay out of the
            // report; the client compares against the accounts they used.
            if (abs($openingSigned) < 0.005 && abs($movementSigned) < 0.005) {
                continue;
            }

            [$openingDebit, $openingCredit] = self::sides($openingSigned, $normal);
            [$closingDebit, $closingCredit] = self::sides($closingSigned, $normal);
            $rowDebit = round((float) ($movement[$code]['debit'] ?? 0), 2);
            $rowCredit = round((float) ($movement[$code]['credit'] ?? 0), 2);

            $rows[] = [
                'code' => $code,
                'name' => (string) $account->name,
                'pos' => (string) $account->pos,
                'group' => (string) $account->account_group,
                'group_label' => $groupLabels[(string) $account->account_group] ?? (string) $account->account_group,
                'saldo_normal' => $normal,
                'is_cash_account' => (bool) $account->is_cash_account,
                'opening_debit' => $openingDebit,
                'opening_credit' => $openingCredit,
                'debit' => $rowDebit,
                'credit' => $rowCredit,
                'closing_debit' => $closingDebit,
                'closing_credit' => $closingCredit,
                'closing_balance' => $closingSigned,
            ];

            $totals['opening_debit'] = round($totals['opening_debit'] + $openingDebit, 2);
            $totals['opening_credit'] = round($totals['opening_credit'] + $openingCredit, 2);
            $totals['debit'] = round($totals['debit'] + $rowDebit, 2);
            $totals['credit'] = round($totals['credit'] + $rowCredit, 2);
            $totals['closing_debit'] = round($totals['closing_debit'] + $closingDebit, 2);
            $totals['closing_credit'] = round($totals['closing_credit'] + $closingCredit, 2);
        }

        $totals['opening_difference'] = round($totals['opening_debit'] - $totals['opening_credit'], 2);
        $totals['difference'] = round($totals['debit'] - $totals['credit'], 2);
        $totals['closing_difference'] = round($totals['closing_debit'] - $totals['closing_credit'], 2);
        $totals['opening_balanced'] = abs($totals['opening_difference']) < 0.005;
        $totals['balanced'] = abs($totals['difference']) < 0.005;
        $totals['closing_balanced'] = abs($totals['closing_difference']) < 0.005;
        $totals['accounts'] = count($rows);

        return [
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
                'opening_before' => $before,
            ],
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * Debit/credit sums per account code, optionally limited to a date window.
     *
     * @return array<string, array{debit: float, credit: float}>
     */
    private static function sums(?int $branchId, ?string $from, ?string $to): array
    {
        $query = DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('chart_of_accounts as accounts', 'accounts.id', '=', 'lines.chart_of_account_id')
            ->where('entries.status', JournalEntry::STATUS_POSTED)
            ->when($branchId, fn ($builder) => $builder->where('entries.branch_id', $branchId))
            ->when($from !== null, fn ($builder) => $builder->whereDate('entries.entry_date', '>=', $from))
            ->when($to !== null, fn ($builder) => $builder->whereDate('entries.entry_date', '<=', $to))
            ->groupBy('accounts.code')
            ->selectRaw('accounts.code as code, SUM(lines.debit) as debit, SUM(lines.credit) as credit');

        $sums = [];

        foreach ($query->get() as $row) {
            $sums[(string) $row->code] = [
                'debit' => round((float) $row->debit, 2),
                'credit' => round((float) $row->credit, 2),
            ];
        }

        return $sums;
    }

    /** @param  array{debit: float, credit: float}|null  $sums */
    private static function signed(?array $sums, string $normal): float
    {
        if ($sums === null) {
            return 0.0;
        }

        return $normal === 'K'
            ? round($sums['credit'] - $sums['debit'], 2)
            : round($sums['debit'] - $sums['credit'], 2);
    }

    /**
     * Split a signed balance into the debit/credit columns of the report: a
     * positive figure sits on the account's normal side.
     *
     * @return array{0: float, 1: float} [debit, credit]
     */
    private static function sides(float $signed, string $normal): array
    {
        $onCreditSide = ($normal === 'K') === ($signed >= 0);

        return $onCreditSide
            ? [0.0, round(abs($signed), 2)]
            : [round(abs($signed), 2), 0.0];
    }
}
