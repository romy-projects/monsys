<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_sales', function (Blueprint $table) {
            $table->foreignId('lpg_price_id')
                ->nullable()
                ->after('cylinder_type')
                ->constrained('lpg_prices')
                ->restrictOnDelete();
            $table->decimal('purchase_price_at_sale', 12, 2)
                ->nullable()
                ->after('lpg_price_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('lpg_price_id')
                ->nullable()
                ->after('cylinder_type')
                ->constrained('lpg_prices')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lpg_price_id');
        });

        Schema::table('daily_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lpg_price_id');
            $table->dropColumn('purchase_price_at_sale');
        });
    }
};
