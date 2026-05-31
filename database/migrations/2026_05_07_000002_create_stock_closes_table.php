<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_closes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('close_date');
            $table->string('cylinder_type', 10);
            $table->unsignedInteger('qty_full')->default(0);
            $table->unsignedInteger('qty_empty')->default(0);
            $table->unsignedInteger('qty_damaged')->default(0);
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->enum('status', ['draft', 'submitted', 'verified'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'close_date', 'cylinder_type'], 'stock_closes_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_closes');
    }
};
