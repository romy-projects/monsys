<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('roles')->pluck('id', 'name');
        $unmappedUsers = DB::table('users')
            ->where(function ($query) use ($roleIds) {
                $query->whereNull('role')
                    ->orWhereNotIn('role', $roleIds->keys()->all());
            })
            ->count();

        if ($unmappedUsers > 0) {
            throw new \RuntimeException(
                "Cannot migrate {$unmappedUsers} user(s): their legacy role has no matching role record."
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')
                ->nullable()
                ->after('role')
                ->constrained('roles')
                ->restrictOnDelete();
        });

        foreach ($roleIds as $name => $id) {
            DB::table('users')
                ->where('role', $name)
                ->update(['role_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });
    }
};
