<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BootstrapSuperAdmin extends Command
{
    protected $signature = 'access:bootstrap-super-admin {email}';

    protected $description = 'Promote the first active Owner Pusat account to Super Admin';

    public function handle(): int
    {
        if (User::query()->whereHas('accessRole', fn ($query) => $query->where('is_super_admin', true))->exists()) {
            $this->error('A Super Admin account is already assigned.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null || $user->status !== 'active' || $user->role !== 'owner_pusat') {
            $this->error('The target must be an existing active Owner Pusat account.');

            return self::FAILURE;
        }

        $role = Role::query()->where('is_super_admin', true)->first();

        if ($role === null || ! $role->is_active) {
            $this->error('The active Super Admin role is not configured.');

            return self::FAILURE;
        }

        if ($this->ask('Re-enter the target email to confirm promotion') !== $email) {
            $this->error('Email confirmation did not match; no changes were made.');

            return self::FAILURE;
        }

        $error = DB::transaction(function () use ($email): ?string {
            $role = Role::query()->where('is_super_admin', true)->lockForUpdate()->first();

            if ($role === null || ! $role->is_active) {
                return 'The active Super Admin role is not configured.';
            }

            if (User::query()->where('role_id', $role->id)->exists()) {
                return 'A Super Admin account is already assigned.';
            }

            $user = User::query()->where('email', $email)->lockForUpdate()->first();

            if ($user === null || $user->status !== 'active' || $user->role !== 'owner_pusat') {
                return 'The target must be an existing active Owner Pusat account.';
            }

            $user->accessRole()->associate($role);
            // The Super Admin posts at the head office, exactly like the
            // provisioned account, so branch-scoped screens have a branch.
            $user->branch_id = Branch::provisionHq()->id;
            // Elevated privileges require a credential only the holder knows.
            $user->must_change_password = true;
            $user->save();

            return null;
        });

        if ($error !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $this->info("{$email} is now assigned the Super Admin role.");
        $this->comment('The legacy role value was retained for compatibility during the authorization rollout.');
        $this->comment('The account now posts at the head office (HQ-001).');

        return self::SUCCESS;
    }
}
