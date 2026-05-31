<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->enum('cylinder_type', ['3kg', '5.5kg', '12kg', '50kg'])->default('3kg');
            $table->unsignedInteger('qty_full')->default(0)->comment('Tabung isi');
            $table->unsignedInteger('qty_empty')->default(0)->comment('Tabung kosong');
            $table->unsignedInteger('qty_damaged')->default(0)->comment('Tabung rusak');
            $table->date('recorded_at');
            $table->timestamps();
            $table->index(['branch_id', 'recorded_at']);
        });

        Schema::create('stock_mutations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('destination_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->enum('cylinder_type', ['3kg', '5.5kg', '12kg', '50kg'])->default('3kg');
            $table->enum('mutation_type', ['in', 'out', 'transfer', 'adjustment']);
            $table->integer('quantity');
            $table->string('reference_no')->nullable();
            $table->text('notes')->nullable();
            $table->date('mutation_date');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_mutations');
        Schema::dropIfExists('stock_items');
    }
};
