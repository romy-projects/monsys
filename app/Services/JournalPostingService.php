<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Support\JournalMapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase 19B / T19B-02: persist the Jurnal Umum.
 *
 * `JournalMapping::build()` stays the single definition of *what* to book; this
 * service decides *when* it is already booked. Every event of the operational
 * tables (invoice sale / HPP / transport, invoice payment, operational cost)
 * becomes one balanced `journal_entries` row with its `journal_entry_lines`.
 *
 * Rules (docs/lapkeu-gap-analysis.md §6):
 *  - idempotent: syncing an unchanged period posts nothing the second time;
 *  - never mutates a posted entry: a changed source voids revision N and posts
 *    N+1, so a report handed to the client can still be reproduced (D5);
 *  - a posted entry whose source no longer produces lines in that window
 *    (deleted / cancelled / moved) is voided, never left live.
 */
class JournalPostingService
{
    /** Human labels used in the entry description. */
    public const EVENT_LABELS = [
        JournalEntry::EVENT_SALES => 'Penjualan',
        JournalEntry::EVENT_HPP => 'HPP',
        JournalEntry::EVENT_TRANSPORT => 'Biaya Transportir',
        JournalEntry::EVENT_PAYMENT => 'Pembayaran Piutang',
        JournalEntry::EVENT_EXPENSE => 'Biaya Operasional',
        JournalEntry::EVENT_SETTLEMENT => 'Pelunasan Hutang Usaha',
        JournalEntry::EVENT_RECEIPT => 'Penerimaan Barang dari Pertamina',
        JournalEntry::EVENT_OPNAME => 'Selisih Persediaan (Opname)',
        JournalEntry::EVENT_OPENING => 'Saldo Awal',
    ];

    /** @var array<string, int> account code => chart_of_accounts.id */
    private array $accountIds = [];

    /**
     * Post (or re-post) every journal entry of a period.
     *
     * @return array{posted: int, revised: int, unchanged: int, voided: int, entries: Collection<int, JournalEntry>}
     */
    public function sync(?int $branchId = null, ?string $startDate = null, ?string $endDate = null, ?int $userId = null): array
    {
        $startDate = $startDate ?: now()->startOfMonth()->toDateString();
        $endDate = $endDate ?: now()->toDateString();
        $groups = $this->group(JournalMapping::build($branchId, $startDate, $endDate));

        return DB::transaction(function () use ($branchId, $startDate, $endDate, $userId, $groups): array {
            $result = [
                'posted' => 0,
                'revised' => 0,
                'unchanged' => 0,
                'voided' => 0,
                'entries' => new Collection,
            ];

            /** @var Collection<string, JournalEntry> $active */
            $active = JournalEntry::query()
                ->posted()
                ->forPeriod($startDate, $endDate)
                ->forBranch($branchId)
                ->with('lines.account')
                ->get()
                ->keyBy(fn (JournalEntry $entry): string => $entry->sourceKey());

            foreach ($groups as $key => $group) {
                $existing = $active->get($key);

                if ($existing && $existing->fingerprint() === $this->fingerprint($group)) {
                    $result['unchanged']++;
                    $result['entries']->push($existing);
                    unset($active[$key]);

                    continue;
                }

                if ($existing) {
                    $this->void($existing, 'Sumber jurnal berubah — posting ulang', $userId);
                    $result['revised']++;
                } else {
                    $result['posted']++;
                }

                $result['entries']->push($this->post($group, $this->nextRevision($group), $userId));
                unset($active[$key]);
            }

            // Anything still active is stale: the source document was deleted,
            // cancelled or moved out of this window.
            foreach ($active as $orphan) {
                $this->void($orphan, 'Sumber jurnal tidak lagi menghasilkan baris pada periode ini', $userId);
                $result['voided']++;
            }

            return $result;
        });
    }

    /**
     * Void an entry so it stops contributing to every report.
     */
    public function void(JournalEntry $entry, string $reason, ?int $userId = null): void
    {
        if ($entry->status === JournalEntry::STATUS_VOID) {
            return;
        }

        $entry->update([
            'status' => JournalEntry::STATUS_VOID,
            'voided_at' => now(),
            'voided_by' => $userId,
            'void_reason' => Str::limit($reason, 250, ''),
        ]);
    }

    /**
     * Create the entry and its lines. The whole sync runs inside one DB
     * transaction, so an unbalanced or unknown-account entry rolls the period
     * back instead of half-booking it.
     *
     * @param  array{source_type: string, source_id: int|null, event: string, date: string, description: string, branch_id: int|null, lines: array<int, array<string, mixed>>}  $group
     */
    private function post(array $group, int $revision, ?int $userId): JournalEntry
    {
        $entry = JournalEntry::create([
            'entry_date' => $group['date'],
            'description' => $group['description'],
            'source_type' => $group['source_type'],
            'source_id' => $group['source_id'],
            'event' => $group['event'],
            'branch_id' => $group['branch_id'],
            'revision' => $revision,
            'status' => JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
            'posted_by' => $userId,
        ]);

        $sort = 0;

        foreach ($group['lines'] as $line) {
            $entry->lines()->create([
                'chart_of_account_id' => $this->accountId((string) $line['account_code']),
                'description' => $line['description'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'is_intercompany' => (bool) $line['is_intercompany'],
                'sort_order' => $sort++,
            ]);
        }

        $entry->load('lines.account');

        if (! $entry->is_balanced) {
            throw new RuntimeException(sprintf(
                'Jurnal %s:%s:%s tidak seimbang (DR %s / CR %s).',
                $group['source_type'],
                $group['source_id'],
                $group['event'],
                number_format($entry->total_debit, 2, ',', '.'),
                number_format($entry->total_credit, 2, ',', '.')
            ));
        }

        $entry->update([
            'entry_no' => sprintf('JU-%s-%06d', $entry->entry_date->format('Y'), $entry->id),
        ]);

        return $entry->refresh()->load('lines.account');
    }

    /**
     * Group the flat mapping rows into journal entries (one per event of one
     * source document).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array{source_type: string, source_id: int|null, event: string, date: string, description: string, branch_id: int|null, lines: array<int, array<string, mixed>>}>
     */
    private function group(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $key = $row['source_type'].':'.$row['source_id'].':'.$row['source'];
            $label = self::EVENT_LABELS[$row['source']] ?? ucfirst((string) $row['source']);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'source_type' => (string) $row['source_type'],
                    'source_id' => $row['source_id'],
                    'event' => (string) $row['source'],
                    'date' => (string) $row['date'],
                    'description' => Str::limit(trim($row['ref'].' — '.$label), 250, ''),
                    'branch_id' => $row['branch_id'],
                    'lines' => [],
                ];
            }

            // The earliest date wins when an event somehow spans two dates.
            $groups[$key]['date'] = min($groups[$key]['date'], (string) $row['date']);
            $groups[$key]['branch_id'] ??= $row['branch_id'];

            $groups[$key]['lines'][] = [
                'account_code' => (string) $row['account_code'],
                'description' => Str::limit((string) $row['description'], 250, ''),
                'debit' => round((float) $row['debit'], 2),
                'credit' => round((float) $row['credit'], 2),
                'is_intercompany' => (bool) $row['is_intercompany'],
            ];
        }

        return $groups;
    }

    /**
     * @param  array{date: string, branch_id: int|null, lines: array<int, array<string, mixed>>}  $group
     */
    private function fingerprint(array $group): string
    {
        return JournalEntry::fingerprintFor($group['date'], $group['branch_id'], $group['lines']);
    }

    /**
     * Revisions are never reused: a voided revision 3 means the next post is 4.
     *
     * @param  array{source_type: string, source_id: int|null, event: string}  $group
     */
    private function nextRevision(array $group): int
    {
        $max = JournalEntry::query()
            ->forSource($group['source_type'], $group['source_id'], $group['event'])
            ->max('revision');

        return ((int) $max) + 1;
    }

    private function accountId(string $code): int
    {
        if (! isset($this->accountIds[$code])) {
            $id = ChartOfAccount::idFor($code);

            if ($id === null) {
                throw new RuntimeException(
                    "Akun {$code} tidak ada di chart of accounts. Jalankan migrasi seed chart of accounts."
                );
            }

            $this->accountIds[$code] = (int) $id;
        }

        return $this->accountIds[$code];
    }
}
