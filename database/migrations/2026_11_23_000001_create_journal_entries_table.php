<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19B / T19B-01: the general-ledger journal header.
 *
 * Phase 19A (Option C) derived the Jurnal from the operational tables on every
 * read. That cannot carry a balance sheet: there was nowhere to store opening
 * balances, no place to lock a period and no way to prove what was booked when
 * an invoice was later edited. Every journal entry now gets a row here.
 *
 * Idempotency: one entry per (source_type, source_id, event, revision). An edit
 * to the source document voids the active entry and posts revision N+1
 * (docs/lapkeu-gap-analysis.md §6, decision D5) so the history stays auditable
 * instead of silently changing a number the client already reported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('entry_no', 40)->nullable()->unique()
                ->comment('JU-<year>-<id>, filled after the row exists');
            $table->date('entry_date');
            $table->string('description', 255)->nullable();

            $table->string('source_type', 40)
                ->comment('invoice|invoice_payment|operational_cost|payable_payment|opening|stock');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('event', 40)
                ->comment('sales|hpp|transport|payment|expense|settlement|opening');

            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('revision')->default(1);

            $table->enum('status', ['posted', 'void'])->default('posted');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();

            $table->timestamps();

            $table->unique(
                ['source_type', 'source_id', 'event', 'revision'],
                'journal_entries_source_revision_unique'
            );
            $table->index(['entry_date', 'status']);
            $table->index(['branch_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
