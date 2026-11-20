<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BALI-18: give LO/SO rows a real link to the Sales Order they belong to.
 *
 * The document chain is string-referenced (po_number / so_number) by legacy design.
 * Reconciliation needs a stable FK, so the column is added and backfilled from so_number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->foreignId('sales_order_id')
                ->nullable()
                ->after('so_number')
                ->constrained('delivery_orders')
                ->nullOnDelete();
        });

        // Chunk in PHP rather than a correlated UPDATE: MySQL rejects the same-table
        // subquery form (error 1093) and this stays portable across MySQL/SQLite.
        DB::table('delivery_orders')
            ->select('id', 'so_number')
            ->whereNotNull('so_number')
            ->where('so_number', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $salesOrderId = DB::table('delivery_orders')
                        ->where('document_type', 'so')
                        ->where('do_number', $row->so_number)
                        ->value('id');

                    if ($salesOrderId) {
                        DB::table('delivery_orders')
                            ->where('id', $row->id)
                            ->update(['sales_order_id' => $salesOrderId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_order_id');
        });
    }
};
