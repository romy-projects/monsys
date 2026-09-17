<?php

namespace App\Services;

use App\Models\DeliveryOrder;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Allocates document numbers (e.g. PO2026-001) on the server.
 *
 * The counter row is read with SELECT ... FOR UPDATE inside a transaction, so concurrent
 * requests queue on the lock and receive consecutive numbers instead of colliding on the
 * unique index of `delivery_orders.do_number` / `invoices.invoice_number`.
 *
 * Callers should allocate and insert inside the same transaction so a failed insert rolls
 * the counter back and leaves no gap:
 *
 *     $po = DB::transaction(function () use ($data) {
 *         $data['do_number'] ??= $this->documentNumbers->next('po');
 *         return DeliveryOrder::create($data);
 *     });
 */
class DocumentNumberService
{
    /** Model + number column that owns each document type. */
    private const SOURCES = [
        'so'  => [DeliveryOrder::class, 'do_number'],
        'do'  => [DeliveryOrder::class, 'do_number'],
        'lo'  => [DeliveryOrder::class, 'do_number'],
        'po'  => [DeliveryOrder::class, 'do_number'],
        'inv' => [Invoice::class, 'invoice_number'],
    ];

    /**
     * Reserve and return the next number for a document type.
     *
     * @param  string   $type  One of: so, do, lo, po, inv
     * @param  int|null $year  Defaults to the current year
     * @return string          e.g. 'PO2026-001'
     */
    public function next(string $type, ?int $year = null): string
    {
        $type = strtolower($type);
        $year ??= (int) now()->year;

        return DB::transaction(function () use ($type, $year): string {
            $row = $this->lockSequenceRow($type, $year);

            $next = (int) $row->last_number + 1;

            // Belt-and-braces: never return a number that is already taken, even if the
            // counter backfill was imperfect. Soft-deleted rows keep their number in the
            // database (so the unique index still rejects it), hence withTrashed().
            do {
                $candidate = $this->format($type, $year, $next);

                if ($this->isTaken($type, $candidate)) {
                    $next++;
                }
            } while ($this->isTaken($type, $candidate));

            DB::table('document_sequences')
                ->where('id', $row->id)
                ->update(['last_number' => $next, 'updated_at' => now()]);

            return $candidate;
        });
    }

    /**
     * Lock (and if needed create) the counter row for a type/year.
     *
     * @return object{id: int|string, last_number: int|string}
     */
    private function lockSequenceRow(string $type, int $year): object
    {
        $query = fn () => DB::table('document_sequences')
            ->where('document_type', $type)
            ->where('year', $year)
            ->lockForUpdate();

        $row = $query()->first();

        if ($row) {
            return $row;
        }

        // First document of a new type/year: create the counter, then lock it so a
        // concurrent caller waits here instead of creating a colliding number.
        DB::table('document_sequences')->insertOrIgnore([
            'document_type' => $type,
            'year'          => $year,
            'last_number'   => 0,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        return $query()->first();
    }

    private function format(string $type, int $year, int $number): string
    {
        return strtoupper($type) . $year . '-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    private function isTaken(string $type, string $candidate): bool
    {
        [$model, $column] = self::SOURCES[$type] ?? [DeliveryOrder::class, 'do_number'];

        return $model::withTrashed()->where($column, $candidate)->exists();
    }
}
