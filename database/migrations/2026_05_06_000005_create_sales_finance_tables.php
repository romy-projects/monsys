<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Harga dasar LPG per jenis tabung
        Schema::create('lpg_prices', function (Blueprint $table) {
            $table->id();
            $table->enum('cylinder_type', ['3kg', '5.5kg', '12kg', '50kg']);
            $table->decimal('purchase_price', 12, 2)->comment('HPP / harga beli');
            $table->decimal('selling_price', 12, 2)->comment('Harga jual standar');
            $table->date('effective_date');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        // Penjualan harian per cabang
        Schema::create('daily_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->enum('cylinder_type', ['3kg', '5.5kg', '12kg', '50kg'])->default('3kg');
            $table->enum('buyer_type', ['retail', 'agent', 'corporate'])->default('retail');
            $table->unsignedInteger('quantity');
            $table->decimal('selling_price', 12, 2);
            $table->decimal('total_revenue', 14, 2)->storedAs('quantity * selling_price');
            $table->date('sale_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['branch_id', 'sale_date']);
        });

        // Biaya operasional harian
        Schema::create('operational_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->enum('cost_category', [
                'fuel',         // BBM
                'salary',       // Gaji
                'logistics',    // Ongkir
                'levy',         // Retribusi
                'other',        // Lain-lain
            ]);
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->date('cost_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['branch_id', 'cost_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_costs');
        Schema::dropIfExists('daily_sales');
        Schema::dropIfExists('lpg_prices');
    }
};
