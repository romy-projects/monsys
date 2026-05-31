<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expeditions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('phone')->nullable();
            $table->string('contact_person')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });

        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->string('do_number')->unique()->comment('e.g. DO2026-001');
            $table->foreignId('origin_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('destination_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('expedition_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('cylinder_type', ['3kg', '5.5kg', '12kg', '50kg'])->default('3kg');
            $table->unsignedInteger('quantity_ordered');
            $table->unsignedInteger('quantity_received')->nullable();
            $table->string('container_number')->nullable();
            $table->date('order_date');
            $table->date('eta')->nullable();
            $table->date('received_date')->nullable();
            $table->enum('status', [
                'draft',
                'pending_approval',
                'approved',
                'in_transit',
                'delivered',
                'cancelled',
            ])->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'order_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_orders');
        Schema::dropIfExists('expeditions');
    }
};
