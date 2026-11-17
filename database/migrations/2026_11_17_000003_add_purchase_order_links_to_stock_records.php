<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_mutations', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->after('created_by')
                ->constrained('delivery_orders')
                ->nullOnDelete();
            $table->unique(
                ['purchase_order_id', 'branch_id', 'mutation_type'],
                'stock_mutations_po_branch_type_unique'
            );
        });

        Schema::table('cylinder_circulations', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->after('created_by')
                ->constrained('delivery_orders')
                ->nullOnDelete()
                ->unique();
        });
    }

    public function down(): void
    {
        Schema::table('cylinder_circulations', function (Blueprint $table) {
            $table->dropUnique(['purchase_order_id']);
            $table->dropConstrainedForeignId('purchase_order_id');
        });

        Schema::table('stock_mutations', function (Blueprint $table) {
            $table->dropUnique('stock_mutations_po_branch_type_unique');
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
