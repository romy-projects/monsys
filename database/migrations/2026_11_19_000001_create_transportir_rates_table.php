<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BALI-10: fixed haulage rate per transportir and cylinder type.
 *
 * Rates are historical (effective-dated) so an old PO/LO keeps the rate that
 * applied when it was created instead of silently picking up a new tariff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transportir_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transportir_id')->constrained()->cascadeOnDelete();
            $table->enum('cylinder_type', ['3kg', '5.5kg', '12kg', '50kg']);
            $table->decimal('rate', 12, 2)->default(0)
                ->comment('Haulage rate charged by the transportir per cylinder (Rp)');
            $table->date('effective_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['transportir_id', 'cylinder_type', 'effective_date'],
                'transportir_rates_unique_effective'
            );
            $table->index(['cylinder_type', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transportir_rates');
    }
};
