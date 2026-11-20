<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BALI-19…22: the money side of a reconciliation.
 *
 * Every discrepancy becomes its own row so each counterparty can settle separately:
 *   - hq / unredeemed_credit        → Case 1, HQ credits the branch for paid-but-not-redeemed units
 *   - hq / short_delivery_credit    → Case 2, HQ credits the branch for units that never arrived
 *   - transportir / transportir_short_claim → Case 2, HQ claims the same units from the transportir
 *   - branch / over_delivery_claim  → Case 3, HQ claims the extra units on the branch's next delivery
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_settlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reconciliation_id')
                ->constrained('cylinder_reconciliations')
                ->cascadeOnDelete();

            $table->string('party', 20);            // hq | branch | transportir
            $table->string('settlement_type', 30);  // see the class docblock

            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);

            // Case 3 carry-over: the PO whose price applies to the extra units.
            $table->foreignId('source_purchase_order_id')
                ->nullable()
                ->constrained('delivery_orders')
                ->nullOnDelete();
            $table->boolean('applies_to_next_delivery')->default(false);

            $table->string('status', 20)->default('open'); // open | settled
            $table->text('notes')->nullable();
            $table->timestamps();

            // BALI-22: one settlement per counterparty obligation per reconciliation.
            $table->unique(['reconciliation_id', 'party', 'settlement_type'], 'recon_settlement_unique');
            $table->index(['party', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_settlements');
    }
};
