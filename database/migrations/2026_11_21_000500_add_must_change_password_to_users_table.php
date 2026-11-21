<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 18 — user password lifecycle.
 *
 * `must_change_password` is set whenever an administrator resets a password to
 * the deployment default (or when the Super Admin account is provisioned), and
 * the ForcePasswordChange middleware keeps the account on the "Change Password"
 * page until the user picks a new one. `password_changed_at` records the last
 * successful self-service change for auditing and support questions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['must_change_password', 'password_changed_at']);
        });
    }
};
