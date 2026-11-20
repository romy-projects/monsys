<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BALI-16: each receivable payment becomes its own row, so a branch keeps a
 * real payment history (date, amount, method, reference) instead of a single
 * paid_amount column plus free text appended to `notes`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivable_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receivable_id')->constrained()->cascadeOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 15, 2);
            $table->enum('method', ['cash', 'transfer', 'giro', 'other'])->default('cash');
            $table->string('reference_no', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['receivable_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_payments');
    }
};
