<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BALI-13: record who bears the expedition cost, and the cost itself.
 *
 * The delivery fee is a flat amount per LO — it is NOT multiplied by the
 * number of cylinders and never derived from the cylinder price.
 *
 * `transportir_lo_number` links an Expedition LO back to the Transportir LO
 * of the same shipment (PO → LO Transportir → LO Expedition).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->string('transportir_lo_number', 100)->nullable()->after('po_number')
                ->comment('Transportir LO number this Expedition LO continues');
            $table->string('cost_borne_by', 20)->nullable()->after('expedition_id')
                ->comment('Who bears the expedition cost: transporter | sum (HQ/Pusat)');
            $table->decimal('expedition_cost', 14, 2)->nullable()->after('cost_borne_by')
                ->comment('Flat delivery fee per LO (Rp), borne by cost_borne_by');
            $table->string('expedition_notes', 255)->nullable()->after('expedition_cost');

            $table->index('transportir_lo_number');
        });

        // Historical LOs: the transportir paid for its own expedition.
        DB::table('delivery_orders')
            ->where('document_type', 'lo')
            ->whereNull('cost_borne_by')
            ->update(['cost_borne_by' => 'transporter']);
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropIndex(['transportir_lo_number']);
            $table->dropColumn([
                'transportir_lo_number',
                'cost_borne_by',
                'expedition_cost',
                'expedition_notes',
            ]);
        });
    }
};
