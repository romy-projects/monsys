<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19B / T19B-01: the debit/credit lines of a journal entry.
 *
 * One row per chart-of-accounts line. `is_intercompany` marks the Pusat ↔
 * Cabang postings (an invoice whose `reference_type` is `branch`) so the
 * consolidated P&L can eliminate them without losing the branch-level books
 * (docs/lapkeu-gap-analysis.md §6, decision D4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chart_of_account_id')
                ->constrained('chart_of_accounts')
                ->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->boolean('is_intercompany')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['chart_of_account_id', 'journal_entry_id'], 'journal_entry_lines_account_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
    }
};
