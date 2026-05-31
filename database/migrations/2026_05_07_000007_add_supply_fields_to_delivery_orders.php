<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->enum('order_type', ['inter_branch', 'supplier'])
                ->default('inter_branch')
                ->after('do_number');

            $table->string('supplier_name')->nullable()->after('order_type');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn(['order_type', 'supplier_name']);
        });
    }
};
