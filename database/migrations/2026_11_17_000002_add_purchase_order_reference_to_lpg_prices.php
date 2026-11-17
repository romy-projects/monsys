<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lpg_prices', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('delivery_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lpg_prices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
