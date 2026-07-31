<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cylinder_circulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('transaction_date');
            $table->string('so_number', 100)->nullable()->comment('Surat Order number');
            $table->enum('transaction_type', ['kirim', 'bongkar_kosong', 'pembelian', 'penyesuaian']);
            $table->text('description')->nullable();
            $table->enum('cylinder_type', ['3kg', '5.5kg', '12kg', '50kg']);
            $table->enum('direction', ['debit', 'kredit']);
            $table->integer('quantity');
            $table->string('container_no', 100)->nullable();
            $table->string('handled_by', 200)->nullable()->comment('Driver/officer name');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'transaction_date']);
            $table->index(['cylinder_type', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cylinder_circulations');
    }
};
