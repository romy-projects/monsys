<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BALI-14: snapshot the transportir rate / expedition delivery fee used when an
 * invoice is created, so a later rate change never re-prices an issued invoice
 * (same protection as daily_sales.purchase_price_at_sale).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('loading_order_number', 100)->nullable()->after('lpg_price_id')
                ->comment('Loading Order this invoice was priced against (optional)');
            $table->decimal('transportir_rate_at_sale', 12, 2)->nullable()->after('unit_price')
                ->comment('Transportir rate per cylinder applied when the invoice was created');
            $table->decimal('expedition_cost_at_sale', 14, 2)->nullable()->after('transportir_rate_at_sale')
                ->comment('Expedition delivery fee of the linked LO when the invoice was created');
            $table->decimal('expedition_fee_amount', 14, 2)->nullable()->after('expedition_cost_at_sale')
                ->comment('Delivery fee actually charged on this invoice (0 when absorbed by HQ)');

            $table->index('loading_order_number');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['loading_order_number']);
            $table->dropColumn([
                'loading_order_number',
                'transportir_rate_at_sale',
                'expedition_cost_at_sale',
                'expedition_fee_amount',
            ]);
        });
    }
};
