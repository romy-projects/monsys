<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19B / T19B-08 (decision D3): a Transportir payable payment needs to say
 * which Kas/Bank account the money left, otherwise the settlement cannot be
 * posted as DR Hutang Usaha / CR Kas-Bank and the cash-flow report has no
 * counterpart. `invoice_payments` got the same column in T19A-03.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payable_payments', function (Blueprint $table) {
            $table->foreignId('cash_account_id')->nullable()->after('method')
                ->constrained('chart_of_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payable_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_account_id');
        });
    }
};
