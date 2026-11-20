<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BALI-17: detailed Transportir payable payments. Each payment keeps its own
 * row and carries the LO reference it settles, so a payable can be paid in
 * several instalments and every instalment stays traceable to its LO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payable_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_order_id')->nullable()->constrained('delivery_orders')->nullOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 15, 2);
            $table->enum('method', ['cash', 'transfer', 'giro', 'other'])->default('cash');
            $table->string('reference_no', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['payable_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payable_payments');
    }
};
