<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->text('description')->nullable();
            $table->string('data_scope', 32)->default('own_branch');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_super_admin')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('user_branch', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'branch_id']);
        });

        $now = now();

        DB::table('roles')->insert([
            [
                'name' => 'owner_pusat',
                'label' => 'Owner Pusat',
                'description' => 'Head office owner with access across all branches.',
                'data_scope' => 'all_branches',
                'is_system' => true,
                'is_super_admin' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'regional_leader',
                'label' => 'Regional Leader',
                'description' => 'Regional operations and approvals.',
                'data_scope' => 'all_branches',
                'is_system' => true,
                'is_super_admin' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'owner_cabang',
                'label' => 'Owner Cabang',
                'description' => 'Owner with access to their own branch.',
                'data_scope' => 'own_branch',
                'is_system' => true,
                'is_super_admin' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'staff_gudang',
                'label' => 'Staff Gudang',
                'description' => 'Operational staff with access to their own branch.',
                'data_scope' => 'own_branch',
                'is_system' => true,
                'is_super_admin' => false,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'super_admin',
                'label' => 'Super Admin',
                'description' => 'Protected system role for access configuration and support.',
                'data_scope' => 'all_branches',
                'is_system' => true,
                'is_super_admin' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_branch');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
