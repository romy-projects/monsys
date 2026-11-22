<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19A / T19A-02: bring `operational_costs` up to the detail the client's
 * EXPENSES sheet actually records — account, quantity × unit price, payment
 * method and the cash/bank account the money left from.
 *
 * Existing rows keep their `amount`; the category is backfilled to the matching
 * chart-of-accounts code so historical expenses already show up in the Jurnal
 * export.
 */
return new class extends Migration
{
    /** Legacy category => chart-of-accounts code. */
    private const CATEGORY_ACCOUNTS = [
        'fuel' => '5-124',      // Biaya Operasional
        'salary' => '5-121',    // Biaya Gaji Karyawan
        'logistics' => '5-123', // Biaya Transport & Tiket
        'levy' => '5-130',      // Biaya Pajak (retribusi)
        'other' => '5-128',     // Biaya Administrasi & Umum
    ];

    public function up(): void
    {
        Schema::table('operational_costs', function (Blueprint $table) {
            $table->foreignId('chart_of_account_id')->nullable()->after('cost_category')
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->decimal('qty', 12, 2)->nullable()->after('description');
            $table->decimal('unit_price', 14, 2)->nullable()->after('qty');
            $table->string('payment_method', 20)->nullable()->after('amount')
                ->comment('cash|transfer|giro|other');
            $table->foreignId('cash_account_id')->nullable()->after('payment_method')
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('reference_no', 50)->nullable()->after('cash_account_id');
        });

        $accountIds = DB::table('chart_of_accounts')->pluck('id', 'code');
        $cashAccountId = $accountIds['1-111'] ?? null;

        foreach (self::CATEGORY_ACCOUNTS as $category => $code) {
            if (! isset($accountIds[$code])) {
                continue;
            }

            DB::table('operational_costs')
                ->where('cost_category', $category)
                ->whereNull('chart_of_account_id')
                ->update([
                    'chart_of_account_id' => $accountIds[$code],
                    'cash_account_id' => $cashAccountId,
                    'payment_method' => 'cash',
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('operational_costs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chart_of_account_id');
            $table->dropConstrainedForeignId('cash_account_id');
            $table->dropColumn(['qty', 'unit_price', 'payment_method', 'reference_no']);
        });
    }
};
