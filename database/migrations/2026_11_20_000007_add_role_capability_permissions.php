<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('roles')->pluck('id', 'name');
        $permissionDefinitions = [
            'system.business_admin' => ['Business administration capability', 'Business role capabilities'],
            'system.regional_access' => ['Regional access capability', 'Business role capabilities'],
            'system.branch_owner' => ['Branch owner capability', 'Business role capabilities'],
            'system.warehouse_staff' => ['Warehouse staff capability', 'Business role capabilities'],
        ];
        $now = now();

        foreach ($permissionDefinitions as $key => [$label, $category]) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                [
                    'label' => $label,
                    'category' => $category,
                    'description' => null,
                    'sort_order' => 1000,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $permissionIds = DB::table('permissions')->pluck('id', 'key');
        $roleGrants = [
            'system.business_admin' => ['owner_pusat', 'super_admin'],
            'system.regional_access' => ['regional_leader', 'super_admin'],
            'system.branch_owner' => ['owner_cabang', 'super_admin'],
            'system.warehouse_staff' => ['staff_gudang', 'super_admin'],
        ];

        foreach ($roleGrants as $permissionKey => $roleNames) {
            foreach ($roleNames as $roleName) {
                if (! isset($roleIds[$roleName])) {
                    throw new RuntimeException("Cannot grant {$permissionKey}; role {$roleName} is missing.");
                }

                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => $permissionIds[$permissionKey],
                    'role_id' => $roleIds[$roleName],
                ]);
            }
        }

        if (isset($roleIds['owner_pusat'], $permissionIds['purchase_orders.create'])) {
            DB::table('permission_role')
                ->where('role_id', $roleIds['owner_pusat'])
                ->where('permission_id', $permissionIds['purchase_orders.create'])
                ->delete();
        }
    }

    public function down(): void
    {
        $ownerPusatId = DB::table('roles')->where('name', 'owner_pusat')->value('id');
        $purchaseOrderCreateId = DB::table('permissions')->where('key', 'purchase_orders.create')->value('id');

        if ($ownerPusatId !== null && $purchaseOrderCreateId !== null) {
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $purchaseOrderCreateId,
                'role_id' => $ownerPusatId,
            ]);
        }

        $keys = [
            'system.business_admin',
            'system.regional_access',
            'system.branch_owner',
            'system.warehouse_staff',
        ];
        $permissionIds = DB::table('permissions')->whereIn('key', $keys)->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
