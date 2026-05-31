<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month'); // 1–12
            $table->string('cylinder_type', 10);  // 3kg, 5.5kg, 12kg, 50kg
            $table->unsignedInteger('target_qty')->default(0);
            $table->decimal('target_revenue', 15, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'year', 'month', 'cylinder_type'], 'sales_targets_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
    }
};
