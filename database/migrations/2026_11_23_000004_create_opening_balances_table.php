<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19B / T19B-12: the balances the client carried over from their previous
 * book, one row per account (docs/lapkeu-gap-analysis.md §6 batch 2).
 *
 * Rows are stored in the account's own normal direction - exactly how the `NS`
 * sheet of the workbook states them - so `amount` on `1-111 Kas` is a debit and
 * `amount` on `2-111 Hutang Usaha` is a credit. JournalMapping turns a branch's
 * rows into one balanced `Saldo Awal` entry and plugs any imbalance to `3-112`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->date('as_of_date')->comment('First day the book is opened with these balances');
            $table->decimal('amount', 15, 2)->default(0)->comment('Stated in the account normal balance direction');
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'chart_of_account_id'], 'opening_balances_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};
