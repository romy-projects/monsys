<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 18 / T18-02 — provision the hidden Super Admin account.
 *
 * The account is created with the reserved primary key `0`, so it can never be
 * confused with a real user record, and `UserResource` filters it out of the
 * user list (the record is therefore unreachable from the UI in both
 * directions: no listing, no edit page).
 *
 * It is a global account, but branch-scoped screens (the branch dashboard, new
 * documents) still need one branch, so the account posts at the head office.
 *
 * MySQL treats an explicit 0 on an AUTO_INCREMENT column as "assign the next
 * value" unless NO_AUTO_VALUE_ON_ZERO is active, and Eloquent's insert path
 * (`insertAndSetId`) always overwrites the key with `lastInsertId()`, so the
 * row is inserted through the query builder with that session mode enabled.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'access:create-super-admin
        {email : Login email for the Super Admin account}
        {--name=Super Admin : Display name}
        {--password= : Initial password (defaults to DEFAULT_USER_PASSWORD)}';

    protected $description = 'Create the hidden Super Admin account at users.id = 0';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $name = (string) ($this->option('name') ?: 'Super Admin');
        $password = (string) ($this->option('password') ?: config('security.password_reset.default', 'password'));
        $minLength = (int) config('security.password_reset.min_length', 8);

        $role = Role::query()->where('is_super_admin', true)->first();

        if ($role === null || ! $role->is_active) {
            $this->error('The active Super Admin role is not configured. Run the access-control migrations first.');

            return self::FAILURE;
        }

        if (User::query()->whereHas('accessRole', fn ($query) => $query->where('is_super_admin', true))->exists()) {
            $this->error('A Super Admin account already exists. There is exactly one, by design.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error("The email {$email} already belongs to another account.");

            return self::FAILURE;
        }

        if (mb_strlen($password) < $minLength) {
            $this->error("The initial password must be at least {$minLength} characters.");

            return self::FAILURE;
        }

        $error = DB::transaction(function () use ($email, $name, $password, $role): ?string {
            $this->enableZeroPrimaryKey();

            // The Super Admin posts at HQ; provisioning is idempotent.
            $hqBranch = Branch::provisionHq();

            DB::table('users')->insert([
                'id' => 0,
                'name' => $name,
                'email' => $email,
                // Authorization runs on role_id = Super Admin; the legacy enum is
                // kept at the highest legacy level for compatibility only.
                'role' => 'owner_pusat',
                'role_id' => $role->id,
                'branch_id' => $hqBranch->id,
                'phone' => null,
                'status' => 'active',
                'email_verified_at' => now(),
                'password' => Hash::make($password),
                // The provisioned password is a handover secret, never a keeper.
                'must_change_password' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (! User::query()->whereKey(0)->exists()) {
                return 'The row was not written to primary key 0 — check the MySQL sql_mode on the application connection.';
            }

            return null;
        });

        if ($error !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $this->info("Super Admin account created at id 0 for {$email}.");
        $this->comment('It is hidden from Users and can only rotate its own password from Change Password.');
        $this->comment('It posts at the head office (HQ-001), so branch-scoped screens have a branch.');
        $this->comment('must_change_password is set: the initial password must be replaced at first sign-in.');

        return self::SUCCESS;
    }

    /**
     * Allow an explicit 0 in the AUTO_INCREMENT primary key for this session.
     */
    private function enableZeroPrimaryKey(): void
    {
        // Only MySQL needs the flag: SQLite and PostgreSQL store an explicit 0
        // as given, which is also why the test suite (SQLite) exercises the
        // same code path without touching sql_mode.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $mode = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS sql_mode')->sql_mode;

        if (str_contains($mode, 'NO_AUTO_VALUE_ON_ZERO')) {
            return;
        }

        $mode = $mode === '' ? 'NO_AUTO_VALUE_ON_ZERO' : $mode.',NO_AUTO_VALUE_ON_ZERO';

        DB::statement('SET SESSION sql_mode = '.DB::getPdo()->quote($mode));
    }
}
