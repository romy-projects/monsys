<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19A / T19A-04 + T19A-05: the remaining columns the client's DELIVERY
 * ORDER and SALES REPORT sheets track but the schema had nowhere to put.
 *
 *  - pertamina_reference          the external Pertamina SO number (`4062182622/1`)
 *  - entered_transportir_at       TGL. MASUK TRANSPORTIR (≠ container loading)
 *  - container_filled_at          TGL. ISI KONTAINER
 *  - expedition_fee_charged_to_buyer  per-shipment decision: bill the delivery
 *    fee to the pangkalan/branch, or leave it as an HQ expense (5-132).
 *    Previously this was only the global EXPEDITION_FEE_CHARGED_TO_BUYER setting.
 *  - invoices.redeemed_on         TGL. TEBUS KE PERTAMINA
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->string('pertamina_reference', 100)->nullable();
            $table->date('entered_transportir_at')->nullable();
            $table->date('container_filled_at')->nullable();
            $table->boolean('expedition_fee_charged_to_buyer')->nullable()
                ->comment('null = follow config(app.expedition_fee_charged_to_buyer)');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->date('redeemed_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn([
                'pertamina_reference',
                'entered_transportir_at',
                'container_filled_at',
                'expedition_fee_charged_to_buyer',
            ]);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('redeemed_on');
        });
    }
};
