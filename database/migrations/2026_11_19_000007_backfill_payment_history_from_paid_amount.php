<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BALI-16 / BALI-17: existing records only know a total paid_amount. Give each
 * of them one "opening balance" payment row so the new payment history starts
 * consistent with the numbers the client already sees.
 */
return new class extends Migration
{
    private const NOTE = 'Saldo awal (migrasi dari paid_amount)';

    public function up(): void
    {
        if (! Schema::hasTable('receivable_payments') || ! Schema::hasTable('payable_payments')) {
            return;
        }

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

    public function down(): void
    {
        // The payment tables themselves are dropped by the two migrations that
        // created them; only the backfilled rows are removed here.
        if (Schema::hasTable('receivable_payments')) {
            DB::table('receivable_payments')->where('notes', self::NOTE)->delete();
        }

        if (Schema::hasTable('payable_payments')) {
            DB::table('payable_payments')->where('notes', self::NOTE)->delete();
        }
    }
};
