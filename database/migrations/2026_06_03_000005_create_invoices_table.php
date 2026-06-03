<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_number', 50)->unique()->comment('e.g. INV2026-001');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('cylinder_type', ['3kg', '5.5kg', '12kg', '50kg'])->default('3kg');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('total_amount', 15, 2)->comment('quantity * unit_price');
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->date('issue_date');
            $table->date('due_date');
            $table->enum('status', ['draft', 'issued', 'partial', 'paid', 'overdue', 'cancelled'])->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
