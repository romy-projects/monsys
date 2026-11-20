<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BALI-18…22: one reconciliation per delivered LO against the PO it fulfils.
 *
 * Quantities are snapshots taken when the LO is delivered (D4: quantity_ordered is
 * the authoritative reconcile quantity on every leg), so later edits cannot rewrite
 * history. `unit_price` is the PO purchase-price snapshot (D5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cylinder_reconciliations', function (Blueprint $table) {
            $table->id();

            // The three legs of the chain, plus the branch that receives the gas.
            $table->foreignId('purchase_order_id')->constrained('delivery_orders')->cascadeOnDelete();
            $table->foreignId('loading_order_id')->constrained('delivery_orders')->cascadeOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('delivery_orders')->nullOnDelete();
            $table->foreignId('branch_id')->constrained('branches');

            $table->string('cylinder_type', 20);

            // Snapshot quantities: paid (PO) → redeemed (SO) → delivered (LO).
            $table->unsignedInteger('quantity_paid');
            $table->unsignedInteger('quantity_redeemed');
            $table->unsignedInteger('quantity_delivered');

            // Derived discrepancies, stored so reports never recompute from live rows.
            $table->unsignedInteger('quantity_unredeemed')->default(0);      // Case 1
            $table->unsignedInteger('quantity_short_delivered')->default(0); // Case 2
            $table->unsignedInteger('quantity_over_delivered')->default(0);  // Case 3

            $table->string('case_type', 20)->default('balanced');
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->string('status', 20)->default('open');
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // BALI-22: reprocessing the same LO must never create a second reconciliation.
            $table->unique(['purchase_order_id', 'loading_order_id'], 'recon_po_lo_unique');
            $table->index(['branch_id', 'status']);
            $table->index(['case_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cylinder_reconciliations');
    }
};
