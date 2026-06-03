<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            // Add expedition_id FK
            $table->foreignId('expedition_id')->nullable()->after('id')
                ->constrained('expeditions')->nullOnDelete();

            // Drop branch_id FK
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->dropForeign(['expedition_id']);
            $table->dropColumn('expedition_id');
        });
    }
};
