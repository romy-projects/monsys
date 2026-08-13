<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SO/LO records to Pertamina don't have a destination branch
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->foreignId('destination_branch_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->foreignId('destination_branch_id')->nullable(false)->change();
        });
    }
};
