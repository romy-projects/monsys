<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19A / T19A-01: the canonical chart of accounts.
 *
 * The client's workbook keeps two copies of the chart (sheets `COA` and `NS`)
 * that drifted apart. This table is the single merged version: every account
 * that appears in either sheet survives exactly once, and the disputed labels
 * are resolved in `2026_11_22_000002_seed_chart_of_accounts.php`.
 *
 * `pos` mirrors the workbook column of the same name: NR = Neraca (balance
 * sheet), LR = Laba Rugi (profit & loss).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->string('pos', 2)->default('LR')->comment('NR = Neraca, LR = Laba Rugi');
            $table->string('account_group', 32)->comment('asset|liability|equity|revenue|cogs|expense');
            $table->string('saldo_normal', 1)->default('D')->comment('D = Debit, K = Kredit');
            $table->boolean('is_cash_account')->default(false)
                ->comment('Kas/Bank: usable as the paying account of an expense or payment');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->index(['pos', 'sort_order']);
            $table->index('account_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
