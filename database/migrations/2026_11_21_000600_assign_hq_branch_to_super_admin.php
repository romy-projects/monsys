<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Post the hidden Super Admin at the head office.
 *
 * The account is provisioned with `branch_id = null`, so every branch-scoped
 * screen had no branch context: the branch dashboard reached
 * `SalesTarget::forMonth(null)` and 500'd, and new documents defaulted to no
 * origin. The Super Admin belongs under HQ, which also carries `owner_pusat` in
 * a seeded installation, so HQ is both its post and the main branch.
 *
 * Installations without a Super Admin yet are left alone: HQ is provisioned by
 * `access:create-super-admin` together with the account it posts there.
 */
return new class extends Migration
{
    private const HQ_CODE = 'HQ-001';

    private const HQ_CODES = ['HQ-001', 'HQ'];

    public function up(): void
    {
        $superAdminRoleIds = DB::table('roles')->where('is_super_admin', true)->pluck('id')->all();

        if ($superAdminRoleIds === []) {
            return;
        }

        if (! DB::table('users')->whereIn('role_id', $superAdminRoleIds)->exists()) {
            return;
        }

        $hqId = $this->headOfficeId();

        DB::table('users')
            ->whereIn('role_id', $superAdminRoleIds)
            ->update(['branch_id' => $hqId, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $hqId = $this->existingHeadOfficeId();

        if ($hqId === null) {
            return;
        }

        $superAdminRoleIds = DB::table('roles')->where('is_super_admin', true)->pluck('id')->all();

        // The branch itself stays: documents may already reference it.
        DB::table('users')
            ->whereIn('role_id', $superAdminRoleIds)
            ->where('branch_id', $hqId)
            ->update(['branch_id' => null, 'updated_at' => now()]);
    }

    /**
     * The head office id, provisioned when the installation has none yet.
     */
    private function headOfficeId(): int
    {
        $existingId = $this->existingHeadOfficeId();

        if ($existingId !== null) {
            // A trashed or deactivated HQ would leave the Super Admin posted at
            // a branch that no picker offers.
            DB::table('branches')->where('id', $existingId)->update([
                'status' => 'active',
                'deleted_at' => null,
                'updated_at' => now(),
            ]);

            return $existingId;
        }

        // Name and city match DatabaseSeeder so seeded and migrated installs agree.
        return (int) DB::table('branches')->insertGetId([
            'code' => self::HQ_CODE,
            'name' => 'Pusat / HQ',
            'city' => 'Jakarta',
            'province' => 'DKI Jakarta',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function existingHeadOfficeId(): ?int
    {
        foreach (self::HQ_CODES as $code) {
            $id = DB::table('branches')->where('code', $code)->value('id');

            if ($id !== null) {
                return (int) $id;
            }
        }

        return null;
    }
};
