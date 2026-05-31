<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', [
                'owner_pusat',
                'regional_leader',
                'owner_cabang',
                'staff_gudang',
            ])->default('staff_gudang')->after('email');

            $table->foreignId('branch_id')
                ->nullable()
                ->after('role')
                ->constrained('branches')
                ->nullOnDelete();

            $table->string('phone')->nullable()->after('branch_id');
            $table->enum('status', ['active', 'inactive'])->default('active')->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn(['role', 'branch_id', 'phone', 'status']);
        });
    }
};
