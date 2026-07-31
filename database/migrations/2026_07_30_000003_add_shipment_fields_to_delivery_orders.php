<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add shipment_status enum column
        DB::statement("ALTER TABLE delivery_orders ADD COLUMN shipment_status ENUM('at_transportir_warehouse','delivered_to_destination') NULL AFTER status");

        // Add transportir_name free-text column
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->string('transportir_name', 200)->nullable()->after('shipment_status')
                ->comment('Free-text name for ad-hoc transportir not registered in Expedition master data');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn('transportir_name');
        });

        DB::statement("ALTER TABLE delivery_orders DROP COLUMN shipment_status");
    }
};
