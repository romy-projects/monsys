<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BALI-16 / BALI-17: existing records only know a total paid_amount. Give each
 * of them one "opening balance" payment row so the new payment history starts
 * consistent with the numbers the client already sees.
 *
 * Phase 19B (2026_11_24_000001_create_payments_tables) replaced the three
 * per-document payment tables with `payments` + `payment_details`. On a fresh
 * install this migration runs before that one and writes to the legacy tables;
 * when it is replayed on a Phase 19B schema (manual re-run, re-seeded database)
 * it writes the same opening rows into the ledger instead of silently doing
 * nothing.
 */
return new class extends Migration
{
    private const NOTE = 'Saldo awal (migrasi dari paid_amount)';

    public function up(): void
    {
        if (Schema::hasTable('receivable_payments') && Schema::hasTable('payable_payments')) {
            $this->backfillLegacyTables();

            return;
        }

        if (Schema::hasTable('payments') && Schema::hasTable('payment_details')) {
            $this->backfillLedger();
        }
    }

    /** Pre-Phase 19B schema: one row per document in its own payment table. */
    private function backfillLegacyTables(): void
    {
        $now = now();

        DB::table('receivables')
            ->where('paid_amount', '>', 0)
            ->orderBy('id')
            ->each(function (object $row) use ($now): void {
                DB::table('receivable_payments')->insert([
                    'receivable_id' => $row->id,
                    'paid_on'       => $row->invoice_date ?: substr((string) $row->created_at, 0, 10),
                    'amount'        => $row->paid_amount,
                    'method'        => 'other',
                    'reference_no'  => null,
                    'notes'         => self::NOTE,
                    'created_by'    => null,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            });

        DB::table('payables')
            ->where('paid_amount', '>', 0)
            ->orderBy('id')
            ->each(function (object $row) use ($now): void {
                DB::table('payable_payments')->insert([
                    'payable_id'        => $row->id,
                    'delivery_order_id' => $row->delivery_order_id,
                    'paid_on'           => $row->paid_at ? substr((string) $row->paid_at, 0, 10) : substr((string) $row->created_at, 0, 10),
                    'amount'            => $row->paid_amount,
                    'method'            => 'other',
                    'reference_no'      => null,
                    'notes'             => self::NOTE,
                    'created_by'        => null,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]);
            });
    }

    /** Phase 19B schema: one payment header + one allocation per document. */
    private function backfillLedger(): void
    {
        $now = now();
        $sequences = [];

        DB::table('receivables')
            ->where('paid_amount', '>', 0)
            ->orderBy('id')
            ->each(function (object $row) use ($now, &$sequences): void {
                $this->insertOpeningPayment(
                    $sequences,
                    'in',
                    $row->invoice_date ?: substr((string) $row->created_at, 0, 10),
                    $row->paid_amount,
                    $row->branch_id ?? null,
                    ['receivable_id' => $row->id],
                    $now
                );
            });

        DB::table('payables')
            ->where('paid_amount', '>', 0)
            ->orderBy('id')
            ->each(function (object $row) use ($now, &$sequences): void {
                $this->insertOpeningPayment(
                    $sequences,
                    'out',
                    $row->paid_at ? substr((string) $row->paid_at, 0, 10) : substr((string) $row->created_at, 0, 10),
                    $row->paid_amount,
                    $this->branchIdForPayable($row),
                    ['payable_id' => $row->id, 'delivery_order_id' => $row->delivery_order_id ?? null],
                    $now
                );
            });

        $this->syncPaymentSequence($sequences);
    }

    /**
     * Write the opening header + its single allocation.
     *
     * @param  array<int, int>  $sequences  year => allocations written so far
     * @param  array<string, int|null>  $target  the allocation's document columns
     */
    private function insertOpeningPayment(
        array &$sequences,
        string $direction,
        string $paidOn,
        mixed $amount,
        ?int $branchId,
        array $target,
        mixed $now
    ): void {
        $year = (int) substr($paidOn, 0, 4);

        // Continue the PM series already in the table, so a replayed migration
        // never collides with the unique index on payments.payment_no.
        $sequences[$year] ??= (int) DB::table('payments')
            ->where('payment_no', 'like', 'PM'.$year.'-%')
            ->count();

        $sequences[$year]++;

        $paymentId = DB::table('payments')->insertGetId([
            'payment_no'      => 'PM'.$year.'-'.str_pad((string) $sequences[$year], 3, '0', STR_PAD_LEFT),
            'direction'       => $direction,
            'paid_on'         => $paidOn,
            'method'          => 'other',
            'cash_account_id' => null,
            'branch_id'       => $branchId,
            'amount'          => $amount,
            'reference_no'    => null,
            'notes'           => self::NOTE,
            'created_by'      => null,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        DB::table('payment_details')->insert([
            'payment_id' => $paymentId,
            ...$target,
            'amount'     => $amount,
            'notes'      => self::NOTE,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Keep DocumentNumberService's 'pmt' counter in step with the PM numbers. */
    private function syncPaymentSequence(array $sequences): void
    {
        if (! Schema::hasTable('document_sequences')) {
            return;
        }

        foreach ($sequences as $year => $last) {
            $existing = DB::table('document_sequences')
                ->where('document_type', 'pmt')
                ->where('year', $year)
                ->first();

            if ($existing) {
                DB::table('document_sequences')->where('id', $existing->id)->update([
                    'last_number' => max((int) $existing->last_number, $last),
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('document_sequences')->insert([
                'document_type' => 'pmt',
                'year' => $year,
                'last_number' => $last,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** A payable belongs to the LO's origin branch (fallback: destination). */
    private function branchIdForPayable(object $row): ?int
    {
        $deliveryOrderId = $row->delivery_order_id ?? null;

        if (! $deliveryOrderId || ! Schema::hasTable('delivery_orders')) {
            return null;
        }

        $deliveryOrder = DB::table('delivery_orders')
            ->where('id', $deliveryOrderId)
            ->first(['origin_branch_id', 'destination_branch_id']);

        return $deliveryOrder?->origin_branch_id ?? $deliveryOrder?->destination_branch_id;
    }

    public function down(): void
    {
        // The payment tables themselves are dropped by the migrations that
        // created them; only the backfilled rows are removed here.
        if (Schema::hasTable('receivable_payments')) {
            DB::table('receivable_payments')->where('notes', self::NOTE)->delete();
        }

        if (Schema::hasTable('payable_payments')) {
            DB::table('payable_payments')->where('notes', self::NOTE)->delete();
        }

        if (Schema::hasTable('payments') && Schema::hasTable('payment_details')) {
            $ids = DB::table('payments')->where('notes', self::NOTE)->pluck('id');

            if ($ids->isNotEmpty()) {
                DB::table('payment_details')->whereIn('payment_id', $ids)->delete();
                DB::table('payments')->whereIn('id', $ids)->delete();
            }
        }
    }
};

