<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19A / T19A-03: invoice payments become a ledger (BALI-16 parity).
 *
 * Until now `InvoiceResource`'s "Pay" action did `paid_amount += ...`, which
 * threw away the payment date and method — exactly the two columns the client's
 * SALES REPORT needs ("TGL. BAYAR PANGKALAN", "METODE PEMBAYARAN").
 */
return new class extends Migration
{
    private const BACKFILL_NOTE = 'Saldo awal (migrasi dari paid_amount)';

    public function up(): void
    {
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 14, 2);
            $table->string('method', 20)->default('cash')->comment('cash|transfer|giro|other');
            $table->foreignId('cash_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('reference_no', 50)->nullable();
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['invoice_id', 'paid_on']);
        });

        // Existing invoices only know a total paid_amount. Give each of them one
        // opening row so the header total and the history agree from day one.
        if (! Schema::hasTable('invoices')) {
            return;
        }

        $now = now();

        DB::table('invoices')
            ->where('paid_amount', '>', 0)
            ->orderBy('id')
            ->each(function (object $row) use ($now): void {
                DB::table('invoice_payments')->insert([
                    'invoice_id' => $row->id,
                    'paid_on' => $row->issue_date ?: substr((string) $row->created_at, 0, 10),
                    'amount' => $row->paid_amount,
                    'method' => 'other',
                    'cash_account_id' => null,
                    'reference_no' => null,
                    'notes' => self::BACKFILL_NOTE,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
    }
};
