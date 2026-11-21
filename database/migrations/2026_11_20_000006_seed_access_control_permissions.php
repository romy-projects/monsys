<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('roles')->pluck('id', 'name');
        $requiredRoles = ['super_admin', 'owner_pusat', 'regional_leader', 'owner_cabang', 'staff_gudang'];
        $missingRoles = array_values(array_diff($requiredRoles, $roleIds->keys()->all()));

        if ($missingRoles !== []) {
            throw new RuntimeException(
                'Cannot seed default permissions; missing role records: '.implode(', ', $missingRoles)
            );
        }

        $unmappedUsers = DB::table('users')
            ->where(function ($query) use ($roleIds) {
                $query->whereNull('role')
                    ->orWhereNotIn('role', $roleIds->keys()->all());
            })
            ->count();

        if ($unmappedUsers > 0) {
            throw new RuntimeException(
                "Cannot sync {$unmappedUsers} user(s): their legacy role has no matching role record."
            );
        }

        foreach ($roleIds as $name => $id) {
            DB::table('users')->where('role', $name)->update(['role_id' => $id]);
        }

        $permissionDefinitions = $this->permissionDefinitions();
        $now = now();

        foreach (array_values($permissionDefinitions) as $sortOrder => $definition) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $definition['key']],
                [
                    'label' => $definition['label'],
                    'category' => $definition['category'],
                    'description' => $definition['description'],
                    'sort_order' => $sortOrder,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $permissionIds = DB::table('permissions')->pluck('id', 'key');
        $keysFor = static function (array $resources) use ($permissionDefinitions): array {
            $keys = [];

            foreach ($resources as $resource => $actions) {
                foreach ($actions as $action) {
                    $key = "{$resource}.{$action}";

                    if (! isset($permissionDefinitions[$key])) {
                        throw new RuntimeException("Unknown permission key: {$key}");
                    }

                    $keys[] = $key;
                }
            }

            return array_values(array_unique($keys));
        };

        $reportViewKeys = array_values(array_filter(
            array_keys($permissionDefinitions),
            static fn (string $key): bool => str_starts_with($key, 'reports.') && str_ends_with($key, '.view')
        ));
        $nonCirculationReportKeys = array_values(array_diff($reportViewKeys, ['reports.cylinder_circulation.view']));

        $regionalKeys = array_merge($keysFor([
            'dashboard.main' => ['view'],
            'dashboard.branch' => ['view'],
            'stock' => ['view'],
            'stock_mutations' => ['view', 'create'],
            'stock_closes' => ['view', 'create'],
            'sales' => ['view', 'create', 'update'],
            'operational_costs' => ['view'],
            'purchase_orders' => ['view', 'approve', 'mark_in_transit', 'cancel'],
            'sales_orders' => ['view', 'create', 'update', 'submit', 'approve', 'cancel'],
            'loading_orders' => ['view', 'create', 'update', 'submit', 'approve', 'mark_loaded', 'cancel'],
            'legacy_delivery_orders' => ['view'],
            'invoices' => ['view'],
            'receivables' => ['view'],
            'payables' => ['view'],
            'branches' => ['view'],
            'customers' => ['view'],
            'vehicles' => ['view'],
            'expeditions' => ['view'],
            'transportirs' => ['view'],
            'prices' => ['view', 'create', 'update'],
            'transportir_rates' => ['view', 'create', 'update'],
            'imports' => ['view_templates', 'run'],
            'scan' => ['run'],
            'device_tokens' => ['manage_own'],
        ]), $nonCirculationReportKeys);

        $ownerCabangKeys = array_merge($keysFor([
            'dashboard.branch' => ['view'],
            'stock' => ['view'],
            'stock_mutations' => ['view', 'create'],
            'stock_closes' => ['view', 'create'],
            'sales' => ['view', 'create', 'update'],
            'operational_costs' => ['view'],
            'purchase_orders' => ['view', 'create', 'update', 'submit', 'receive', 'cancel', 'upload_receipt'],
            'loading_orders' => ['view'],
            'invoices' => ['view'],
            'receivables' => ['view'],
            'payables' => ['view'],
            'branches' => ['view'],
            'customers' => ['view', 'create', 'update'],
            'vehicles' => ['view'],
            'expeditions' => ['view'],
            'transportirs' => ['view'],
            'prices' => ['view'],
            'transportir_rates' => ['view'],
            'scan' => ['run'],
            'device_tokens' => ['manage_own'],
        ]), $nonCirculationReportKeys);

        $staffGudangKeys = $keysFor([
            'dashboard.branch' => ['view'],
            'stock' => ['view'],
            'stock_mutations' => ['view', 'create'],
            'stock_closes' => ['view', 'create'],
            'sales' => ['view', 'create', 'update'],
            'purchase_orders' => ['view', 'create', 'update', 'submit', 'receive', 'upload_receipt'],
            'loading_orders' => ['view'],
            'branches' => ['view'],
            'customers' => ['view'],
            'vehicles' => ['view'],
            'expeditions' => ['view'],
            'transportirs' => ['view'],
            'prices' => ['view'],
            'scan' => ['run'],
            'device_tokens' => ['manage_own'],
        ]);

        $restrictedPermissions = ['access_control.manage', 'impersonation.start'];
        $catalogKeys = array_keys($permissionDefinitions);
        $roleGrants = [
            'super_admin' => $catalogKeys,
            'owner_pusat' => array_values(array_diff($catalogKeys, $restrictedPermissions)),
            'regional_leader' => $regionalKeys,
            'owner_cabang' => $ownerCabangKeys,
            'staff_gudang' => $staffGudangKeys,
        ];

        foreach ($roleGrants as $roleName => $keys) {
            $rows = [];

            foreach (array_unique($keys) as $key) {
                if (! $permissionIds->has($key)) {
                    throw new RuntimeException("Permission was not persisted: {$key}");
                }

                $rows[] = [
                    'permission_id' => $permissionIds[$key],
                    'role_id' => $roleIds[$roleName],
                ];
            }

            DB::table('permission_role')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        $keys = array_keys($this->permissionDefinitions());
        $permissionIds = DB::table('permissions')->whereIn('key', $keys)->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }

    private function permissionDefinitions(): array
    {
        $actionLabels = [
            'view' => 'View',
            'create' => 'Create',
            'update' => 'Update',
            'delete' => 'Delete',
            'submit' => 'Submit',
            'approve' => 'Approve',
            'mark_in_transit' => 'Mark in transit',
            'mark_loaded' => 'Mark loaded',
            'receive' => 'Receive',
            'cancel' => 'Cancel',
            'upload_receipt' => 'Upload receipt',
            'issue' => 'Issue',
            'pay' => 'Pay',
            'record_payment' => 'Record payment',
            'reverse_payment' => 'Reverse payment',
            'reconcile' => 'Reconcile',
            'settle' => 'Settle',
            'void' => 'Void',
            'view_templates' => 'View templates',
            'run' => 'Run',
            'manage_own' => 'Manage own',
        ];

        $permissionDefinitions = [];
        $addResource = static function (string $resource, string $label, string $category, array $actions) use (&$permissionDefinitions, $actionLabels): void {
            foreach ($actions as $action) {
                $key = "{$resource}.{$action}";
                $permissionDefinitions[$key] = [
                    'key' => $key,
                    'label' => "{$actionLabels[$action]} {$label}",
                    'category' => $category,
                    'description' => null,
                ];
            }
        };

        $resources = [
            ['stock', 'Stock', 'Stock', ['view']],
            ['stock_mutations', 'Stock Mutations', 'Stock', ['view', 'create']],
            ['stock_closes', 'Stock Closes', 'Stock', ['view', 'create']],
            ['sales', 'Sales', 'Sales', ['view', 'create', 'update', 'delete']],
            ['operational_costs', 'Operational Costs', 'Finance', ['view', 'create', 'update', 'delete']],
            ['purchase_orders', 'Purchase Orders', 'Orders', ['view', 'create', 'update', 'delete', 'submit', 'approve', 'mark_in_transit', 'receive', 'cancel', 'upload_receipt']],
            ['sales_orders', 'Sales Orders', 'Orders', ['view', 'create', 'update', 'delete', 'submit', 'approve', 'cancel']],
            ['loading_orders', 'Loading Orders', 'Orders', ['view', 'create', 'update', 'delete', 'submit', 'approve', 'mark_loaded', 'cancel']],
            ['legacy_delivery_orders', 'Legacy Delivery Orders', 'Orders', ['view']],
            ['invoices', 'Invoices', 'Finance', ['view', 'create', 'update', 'delete', 'issue', 'pay']],
            ['receivables', 'Receivables', 'Finance', ['view', 'create', 'update', 'delete', 'record_payment', 'reverse_payment']],
            ['payables', 'Payables', 'Finance', ['view', 'create', 'update', 'delete', 'record_payment', 'reverse_payment']],
            ['reconciliations', 'Reconciliations', 'Reconciliation', ['view', 'create', 'reconcile', 'settle', 'void']],
            ['cylinder_circulations', 'Cylinder Circulations', 'Stock', ['view', 'create', 'update', 'delete']],
            ['branches', 'Branches', 'Master Data', ['view', 'create', 'update', 'delete']],
            ['users', 'Users', 'Master Data', ['view', 'create', 'update', 'delete']],
            ['customers', 'Customers', 'Master Data', ['view', 'create', 'update', 'delete']],
            ['vehicles', 'Vehicles', 'Master Data', ['view', 'create', 'update', 'delete']],
            ['expeditions', 'Expeditions', 'Master Data', ['view', 'create', 'update', 'delete']],
            ['transportirs', 'Transportirs', 'Master Data', ['view', 'create', 'update', 'delete']],
            ['prices', 'LPG Prices', 'Master Data', ['view', 'create', 'update']],
            ['transportir_rates', 'Transportir Rates', 'Master Data', ['view', 'create', 'update']],
            ['sales_targets', 'Sales Targets', 'Master Data', ['view', 'create', 'update', 'delete']],
        ];

        foreach ($resources as [$resource, $label, $category, $actions]) {
            $addResource($resource, $label, $category, $actions);
        }

        $permissionDefinitions['dashboard.main.view'] = [
            'key' => 'dashboard.main.view',
            'label' => 'View Main Dashboard',
            'category' => 'Dashboard',
            'description' => null,
        ];
        $permissionDefinitions['dashboard.branch.view'] = [
            'key' => 'dashboard.branch.view',
            'label' => 'View Branch Dashboard',
            'category' => 'Dashboard',
            'description' => null,
        ];

        $reportLabels = [
            'profit_loss' => 'Profit & Loss Report',
            'stock_summary' => 'Stock Summary Report',
            'shipment_tracking' => 'Shipment Tracking Report',
            'sales_period' => 'Sales Period Report',
            'branch_ranking' => 'Branch Ranking Report',
            'stock_audit' => 'Stock Audit Report',
            'hpp' => 'HPP Report',
            'receivables_aging' => 'Receivables Aging Report',
            'cylinder_circulation' => 'Cylinder Circulation Report',
        ];

        foreach ($reportLabels as $key => $label) {
            $permissionKey = "reports.{$key}.view";
            $permissionDefinitions[$permissionKey] = [
                'key' => $permissionKey,
                'label' => "View {$label}",
                'category' => 'Reports',
                'description' => null,
            ];
        }

        $specialPermissions = [
            'imports.view_templates' => ['View Import Templates', 'Import Data'],
            'imports.run' => ['Import Data', 'Import Data'],
            'notification_log.view' => ['View Notification Log', 'Notifications'],
            'scan.run' => ['Run Document Scan', 'Scanner'],
            'device_tokens.manage_own' => ['Manage Own Device Tokens', 'Device Tokens'],
            'access_control.manage' => ['Manage Roles and Permissions', 'Access Control'],
            'impersonation.start' => ['Start User Impersonation', 'Access Control'],
        ];

        foreach ($specialPermissions as $key => [$label, $category]) {
            $permissionDefinitions[$key] = [
                'key' => $key,
                'label' => $label,
                'category' => $category,
                'description' => null,
            ];
        }

        return $permissionDefinitions;
    }
};
