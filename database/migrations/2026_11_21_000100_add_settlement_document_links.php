<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D6 (2026-11-20): a reconciliation settlement is settled by the branch's next
 * commercial document (Invoice / SO / LOT / LOE) — never by editing a receivable
 * or payable, which keep their own transaction history.
 *
 * The link below records which document carried the settlement, when, and by whom.
 * `status` is a plain string column (open | settled | void), so no enum change is
 * needed for the new `void` state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_settlements', function (Blueprint $table) {
            // invoice | sales_order | loading_order
            $table->string('settled_by_type', 30)->nullable()->after('status');
            $table->unsignedBigInteger('settled_by_id')->nullable()->after('settled_by_type');
            $table->timestamp('settled_at')->nullable()->after('settled_by_id');

            $table->foreignId('settled_by_user_id')
                ->nullable()
                ->after('settled_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['settled_by_type', 'settled_by_id'], 'recon_settlement_document_index');
        });
    }

    public function down(): void
    {
        Schema::table('reconciliation_settlements', function (Blueprint $table) {
            $table->dropForeign(['settled_by_user_id']);
            $table->dropIndex('recon_settlement_document_index');
            $table->dropColumn(['settled_by_type', 'settled_by_id', 'settled_at', 'settled_by_user_id']);
        });
    }
};
