<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('roles')->pluck('id', 'name');
        $definitions = [
            'stock.create' => 'Create Stock Items',
            'stock.update' => 'Update Stock Items',
            'stock.delete' => 'Delete Stock Items',
            'stock_closes.update' => 'Update Stock Closes',
            'stock_closes.verify' => 'Verify Stock Closes',
        ];
        $now = now();

        foreach ($definitions as $key => $label) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                [
                    'label' => $label,
                    'category' => 'Stock',
                    'description' => null,
                    'sort_order' => 1001,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $roleGrants = [
            'owner_pusat' => ['purchase_orders.create', 'stock_closes.verify'],
            'owner_cabang' => ['stock.create', 'stock.update', 'stock.delete', 'stock_closes.update', 'sales.delete'],
            'staff_gudang' => ['stock.create', 'stock.update', 'stock.delete', 'stock_closes.update', 'sales.delete'],
            'regional_leader' => ['stock_closes.verify'],
            'super_admin' => ['stock.create', 'stock.update', 'stock.delete', 'stock_closes.update', 'stock_closes.verify', 'sales.delete'],
        ];
        $roleGrants['owner_cabang'] = array_merge(
            $roleGrants['owner_cabang'],
            ['invoices.create', 'legacy_delivery_orders.view']
        );

        $allPermissionIds = DB::table('permissions')
            ->whereIn('key', [
                'stock.create',
                'stock.update',
                'stock.delete',
                'stock_closes.update',
                'stock_closes.verify',
                'sales.delete',
                'purchase_orders.create',
                'invoices.create',
                'legacy_delivery_orders.view',
            ])
            ->pluck('id', 'key');

        foreach ($roleGrants as $roleName => $keys) {
            if (! isset($roleIds[$roleName])) {
                throw new RuntimeException("Cannot grant stock permissions; role {$roleName} is missing.");
            }

            foreach ($keys as $key) {
                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => $allPermissionIds[$key],
                    'role_id' => $roleIds[$roleName],
                ]);
            }
        }

        $salesPermissionIds = DB::table('permissions')
            ->whereIn('key', ['sales.create', 'sales.update', 'sales.delete'])
            ->pluck('id', 'key');
        $roleRevocations = [
            'owner_pusat' => ['sales.create', 'sales.update', 'sales.delete'],
            'regional_leader' => ['sales.create', 'sales.update'],
        ];

        foreach ($roleRevocations as $roleName => $keys) {
            foreach ($keys as $key) {
                if (! isset($roleIds[$roleName], $salesPermissionIds[$key])) {
                    continue;
                }

                DB::table('permission_role')
                    ->where('role_id', $roleIds[$roleName])
                    ->where('permission_id', $salesPermissionIds[$key])
                    ->delete();
            }
        }
    }

    public function down(): void
    {
        $roleIds = DB::table('roles')->pluck('id', 'name');
        $salesPermissionIds = DB::table('permissions')
            ->whereIn('key', ['sales.create', 'sales.update', 'sales.delete'])
            ->pluck('id', 'key');
        $roleRestorations = [
            'owner_pusat' => ['sales.create', 'sales.update', 'sales.delete'],
            'regional_leader' => ['sales.create', 'sales.update'],
        ];

        foreach ($roleRestorations as $roleName => $keys) {
            foreach ($keys as $key) {
                if (isset($roleIds[$roleName], $salesPermissionIds[$key])) {
                    DB::table('permission_role')->insertOrIgnore([
                        'permission_id' => $salesPermissionIds[$key],
                        'role_id' => $roleIds[$roleName],
                    ]);
                }
            }
        }

        $rolesToRevoke = [
            'owner_pusat' => ['purchase_orders.create'],
            'owner_cabang' => ['invoices.create', 'legacy_delivery_orders.view'],
        ];
        $grantPermissionIds = DB::table('permissions')
            ->whereIn('key', ['purchase_orders.create', 'invoices.create', 'legacy_delivery_orders.view'])
            ->pluck('id', 'key');

        foreach ($rolesToRevoke as $roleName => $keys) {
            foreach ($keys as $key) {
                if (isset($roleIds[$roleName], $grantPermissionIds[$key])) {
                    DB::table('permission_role')
                        ->where('role_id', $roleIds[$roleName])
                        ->where('permission_id', $grantPermissionIds[$key])
                        ->delete();
                }
            }
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', [
                'stock.create',
                'stock.update',
                'stock.delete',
                'stock_closes.update',
                'stock_closes.verify',
            ])
            ->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();

        $salesDeleteId = $salesPermissionIds['sales.delete'] ?? null;

        if ($salesDeleteId !== null) {
            DB::table('permission_role')
                ->whereIn('role_id', array_values($roleIds->only(['owner_cabang', 'staff_gudang'])->all()))
                ->where('permission_id', $salesDeleteId)
                ->delete();
        }

        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
