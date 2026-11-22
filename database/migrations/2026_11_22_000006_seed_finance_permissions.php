<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 19A / T19A-09: permissions for the finance parity work.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> */
    private const DEFINITIONS = [
        'coa.view' => ['View Chart of Accounts', 'Finance'],
        'coa.manage' => ['Manage Chart of Accounts', 'Finance'],
        'reports.journal.view' => ['View Journal (Jurnal Umum) Report', 'Reports'],
        'reports.sales_detail.view' => ['View Sales Detail Report', 'Reports'],
    ];

    /** @var array<string, array<int, string>> */
    private const GRANTS = [
        'owner_pusat' => ['coa.view', 'coa.manage', 'reports.journal.view', 'reports.sales_detail.view'],
        'owner_cabang' => ['coa.view', 'reports.journal.view', 'reports.sales_detail.view'],
        'regional_leader' => ['coa.view', 'reports.journal.view', 'reports.sales_detail.view'],
        'super_admin' => ['coa.view', 'coa.manage', 'reports.journal.view', 'reports.sales_detail.view'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::DEFINITIONS as $key => [$label, $category]) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                [
                    'label' => $label,
                    'category' => $category,
                    'description' => null,
                    'sort_order' => 2000,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $roleIds = DB::table('roles')->pluck('id', 'name');
        $permissionIds = DB::table('permissions')
            ->whereIn('key', array_keys(self::DEFINITIONS))
            ->pluck('id', 'key');

        foreach (self::GRANTS as $roleName => $keys) {
            if (! isset($roleIds[$roleName])) {
                continue;
            }

            foreach ($keys as $key) {
                if (! isset($permissionIds[$key])) {
                    continue;
                }

                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => $permissionIds[$key],
                    'role_id' => $roleIds[$roleName],
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('key', array_keys(self::DEFINITIONS))
            ->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
