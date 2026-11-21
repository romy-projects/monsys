<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DailySale;
use App\Models\LpgPrice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RolePermissionSchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_seeds_existing_roles_and_a_protected_super_admin_role(): void
    {
        $this->assertSame(
            [
                'owner_pusat',
                'regional_leader',
                'owner_cabang',
                'staff_gudang',
                'super_admin',
            ],
            Role::query()->orderBy('id')->pluck('name')->all()
        );

        $superAdmin = Role::query()->where('name', 'super_admin')->firstOrFail();

        $this->assertTrue($superAdmin->is_system);
        $this->assertTrue($superAdmin->is_super_admin);
        $this->assertSame(Role::SCOPE_ALL_BRANCHES, $superAdmin->data_scope);
    }

    #[Test]
    public function roles_users_permissions_and_assigned_branches_are_related(): void
    {
        $role = Role::query()->where('name', 'owner_cabang')->firstOrFail();
        $permission = Permission::create([
            'key' => 'reports.view',
            'label' => 'View reports',
            'category' => 'Reports',
        ]);
        $role->permissions()->attach($permission);

        $branch = Branch::create([
            'code' => 'BR-01',
            'name' => 'Branch One',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);

        $user = User::create([
            'name' => 'Branch Owner',
            'email' => 'branch-owner@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_cabang',
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
        $user->accessRole()->associate($role);
        $user->save();
        $user->assignedBranches()->attach($branch);

        $this->assertTrue($user->fresh()->accessRole->is($role));
        $this->assertTrue($role->fresh()->permissions->contains($permission));
        $this->assertTrue($branch->assignedUsers->contains($user));
    }

    #[Test]
    public function it_backfills_existing_users_without_changing_the_legacy_role_value(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });

        $user = User::create([
            'name' => 'Existing Branch Owner',
            'email' => 'existing-owner@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_cabang',
            'status' => 'active',
        ]);

        $migration = require database_path('migrations/2026_11_20_000005_add_role_id_to_users_table.php');
        $migration->up();

        $user->refresh();

        $this->assertSame('owner_cabang', $user->role);
        $this->assertSame(
            Role::query()->where('name', 'owner_cabang')->value('id'),
            $user->role_id
        );
    }

    #[Test]
    public function it_seeds_default_permissions_without_granting_admin_settings_to_business_roles(): void
    {
        $superAdmin = Role::query()->where('name', 'super_admin')->with('permissions')->firstOrFail();
        $ownerPusat = Role::query()->where('name', 'owner_pusat')->with('permissions')->firstOrFail();
        $regionalLeader = Role::query()->where('name', 'regional_leader')->with('permissions')->firstOrFail();
        $ownerCabang = Role::query()->where('name', 'owner_cabang')->with('permissions')->firstOrFail();
        $staffGudang = Role::query()->where('name', 'staff_gudang')->with('permissions')->firstOrFail();

        $this->assertGreaterThan(0, Permission::query()->count());
        $this->assertSame(Permission::query()->count(), $superAdmin->permissions->count());
        $this->assertTrue($superAdmin->permissions->contains('key', 'access_control.manage'));
        $this->assertTrue($superAdmin->permissions->contains('key', 'impersonation.start'));
        $this->assertTrue($superAdmin->permissions->contains('key', 'system.business_admin'));

        $this->assertFalse($ownerPusat->permissions->contains('key', 'access_control.manage'));
        $this->assertFalse($ownerPusat->permissions->contains('key', 'impersonation.start'));
        $this->assertTrue($ownerPusat->permissions->contains('key', 'system.business_admin'));
        $this->assertTrue($ownerPusat->permissions->contains('key', 'users.delete'));
        $this->assertTrue($ownerPusat->permissions->contains('key', 'purchase_orders.create'));
        $this->assertTrue($ownerPusat->permissions->contains('key', 'stock_closes.verify'));
        $this->assertFalse($ownerPusat->permissions->contains('key', 'sales.create'));
        $this->assertFalse($ownerPusat->permissions->contains('key', 'sales.delete'));

        $this->assertSame('all_branches', $regionalLeader->data_scope);
        $this->assertTrue($regionalLeader->permissions->contains('key', 'system.regional_access'));
        $this->assertTrue($regionalLeader->permissions->contains('key', 'reports.profit_loss.view'));
        $this->assertFalse($regionalLeader->permissions->contains('key', 'sales.create'));
        $this->assertFalse($regionalLeader->permissions->contains('key', 'invoices.pay'));

        $this->assertSame('own_branch', $ownerCabang->data_scope);
        $this->assertTrue($ownerCabang->permissions->contains('key', 'system.branch_owner'));
        $this->assertTrue($ownerCabang->permissions->contains('key', 'stock.create'));
        $this->assertTrue($ownerCabang->permissions->contains('key', 'stock_closes.update'));
        $this->assertTrue($ownerCabang->permissions->contains('key', 'sales.delete'));
        $this->assertTrue($ownerCabang->permissions->contains('key', 'invoices.view'));
        $this->assertTrue($ownerCabang->permissions->contains('key', 'invoices.create'));
        $this->assertTrue($ownerCabang->permissions->contains('key', 'legacy_delivery_orders.view'));
        $this->assertFalse($ownerCabang->permissions->contains('key', 'invoices.pay'));

        $this->assertSame('own_branch', $staffGudang->data_scope);
        $this->assertTrue($staffGudang->permissions->contains('key', 'system.warehouse_staff'));
        $this->assertTrue($staffGudang->permissions->contains('key', 'stock.create'));
        $this->assertTrue($staffGudang->permissions->contains('key', 'stock_closes.update'));
        $this->assertTrue($staffGudang->permissions->contains('key', 'sales.delete'));
        $this->assertFalse($staffGudang->permissions->contains('key', 'stock_closes.verify'));
        $this->assertTrue($regionalLeader->permissions->contains('key', 'stock_closes.verify'));
        $this->assertTrue($staffGudang->permissions->contains('key', 'sales.create'));
        $this->assertTrue($staffGudang->permissions->contains('key', 'stock_mutations.create'));
        $this->assertFalse($staffGudang->permissions->contains('key', 'reports.stock_summary.view'));
        $this->assertFalse($staffGudang->permissions->contains('key', 'invoices.view'));
    }

    #[Test]
    public function it_syncs_role_ids_for_accounts_created_or_changed_before_authorization_migration(): void
    {
        $user = User::create([
            'name' => 'Existing Staff',
            'email' => 'existing-staff@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_cabang',
            'status' => 'active',
        ]);
        $user->accessRole()->associate(Role::query()->where('name', 'staff_gudang')->firstOrFail());
        $user->save();

        $migration = require database_path('migrations/2026_11_20_000006_seed_access_control_permissions.php');
        $migration->up();

        $this->assertSame(
            Role::query()->where('name', 'owner_cabang')->value('id'),
            $user->fresh()->role_id
        );
    }

    #[Test]
    public function it_checks_permissions_through_gates_and_limits_super_admin_settings(): void
    {
        $branchOwner = User::create([
            'name' => 'Branch Owner',
            'email' => 'gate-owner@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_cabang',
            'status' => 'active',
        ]);
        $branchOwner->accessRole()->associate(Role::query()->where('name', 'owner_cabang')->firstOrFail());
        $branchOwner->save();

        $superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'gate-admin@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'status' => 'active',
        ]);
        $superAdmin->accessRole()->associate(Role::query()->where('name', 'super_admin')->firstOrFail());
        $superAdmin->save();

        $this->assertTrue(Gate::forUser($branchOwner)->allows('sales.create'));
        $this->assertFalse(Gate::forUser($branchOwner)->allows('access_control.manage'));
        $this->assertTrue($branchOwner->fresh()->isOwnerCabang());
        $this->assertTrue($branchOwner->fresh()->canViewFinance());
        $this->assertTrue($branchOwner->fresh()->canCreatePurchaseOrder());
        $this->assertFalse($branchOwner->fresh()->canApproveOrders());
        $this->assertTrue(Gate::forUser($superAdmin)->allows('access_control.manage'));
        $this->assertTrue($superAdmin->fresh()->isOwnerPusat());
        $this->assertTrue($superAdmin->fresh()->canCreatePurchaseOrder());
        $this->assertFalse(Gate::forUser($superAdmin)->allows('unknown.permission'));
    }

    #[Test]
    public function filament_model_gates_resolve_catalog_permissions_and_record_scope(): void
    {
        $ownBranch = Branch::create([
            'code' => 'GATE-OWN',
            'name' => 'Gate Own Branch',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);
        $otherBranch = Branch::create([
            'code' => 'GATE-OTHER',
            'name' => 'Gate Other Branch',
            'city' => 'Bandung',
            'status' => 'active',
        ]);
        $user = User::create([
            'name' => 'Gate Scope User',
            'email' => 'gate-scope@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_cabang',
            'branch_id' => $ownBranch->id,
            'status' => 'active',
        ]);
        $ownStock = StockItem::create([
            'branch_id' => $ownBranch->id,
            'cylinder_type' => '3kg',
            'qty_full' => 10,
            'qty_empty' => 0,
            'qty_damaged' => 0,
            'recorded_at' => today(),
        ]);
        $otherStock = StockItem::create([
            'branch_id' => $otherBranch->id,
            'cylinder_type' => '3kg',
            'qty_full' => 10,
            'qty_empty' => 0,
            'qty_damaged' => 0,
            'recorded_at' => today(),
        ]);

        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('viewAny', StockItem::class));
        $this->assertTrue($gate->allows('create', DailySale::class));
        $this->assertTrue($gate->allows('create', StockItem::class));
        $this->assertTrue($gate->allows('view', $ownStock));
        $this->assertFalse($gate->allows('view', $otherStock));
        $this->assertFalse($gate->allows('viewAny', User::class));
    }

    #[Test]
    public function it_synchronizes_role_id_when_the_legacy_role_field_changes(): void
    {
        $user = User::create([
            'name' => 'Role Transition User',
            'email' => 'role-transition@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_cabang',
            'status' => 'active',
        ]);

        $user->update(['role' => 'staff_gudang']);

        $this->assertSame(
            Role::query()->where('name', 'staff_gudang')->value('id'),
            $user->fresh()->role_id
        );
    }

    #[Test]
    public function it_applies_all_own_and_assigned_branch_scopes(): void
    {
        $ownBranch = Branch::create([
            'code' => 'SCOPE-01',
            'name' => 'Own Branch',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);
        $assignedBranch = Branch::create([
            'code' => 'SCOPE-02',
            'name' => 'Assigned Branch',
            'city' => 'Bandung',
            'status' => 'active',
        ]);

        $branchOwner = User::create([
            'name' => 'Scoped Branch Owner',
            'email' => 'scope-owner@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_cabang',
            'branch_id' => $ownBranch->id,
            'status' => 'active',
        ]);
        $branchOwner->accessRole()->associate(Role::query()->where('name', 'owner_cabang')->firstOrFail());
        $branchOwner->save();

        $this->assertTrue($branchOwner->canAccessBranch((int) $ownBranch->id));
        $this->assertFalse($branchOwner->canAccessBranch((int) $assignedBranch->id));
        $this->assertSame(
            [$ownBranch->id],
            $branchOwner->applyAccessibleBranchScope(Branch::query(), 'id')->pluck('id')->all()
        );

        $assignedRole = Role::create([
            'name' => 'branch_auditor',
            'label' => 'Branch Auditor',
            'data_scope' => Role::SCOPE_ASSIGNED_BRANCHES,
        ]);
        $assignedUser = User::create([
            'name' => 'Assigned User',
            'email' => 'assigned-user@example.test',
            'password' => bcrypt('password'),
            'role' => 'staff_gudang',
            'branch_id' => $ownBranch->id,
            'status' => 'active',
        ]);
        $assignedUser->accessRole()->associate($assignedRole);
        $assignedUser->save();
        $assignedUser->assignedBranches()->attach($assignedBranch);

        $this->assertSame(Role::SCOPE_ASSIGNED_BRANCHES, $assignedUser->accessRole->data_scope);
        $this->assertTrue($assignedUser->accessRole->is_active);
        $this->assertTrue($assignedUser->assignedBranches()->where('branches.id', $assignedBranch->id)->exists());
        $this->assertFalse($assignedUser->canAccessBranch((int) $ownBranch->id));
        $this->assertTrue($assignedUser->canAccessBranch((int) $assignedBranch->id));
        $this->assertSame(
            [$assignedBranch->id],
            $assignedUser->applyAccessibleBranchScope(Branch::query(), 'id')->pluck('id')->all()
        );

        $regionalLeader = User::create([
            'name' => 'Regional Leader',
            'email' => 'scope-regional@example.test',
            'password' => bcrypt('password'),
            'role' => 'regional_leader',
            'status' => 'active',
        ]);
        $regionalLeader->accessRole()->associate(Role::query()->where('name', 'regional_leader')->firstOrFail());
        $regionalLeader->save();

        $this->assertTrue($regionalLeader->canAccessBranch((int) $ownBranch->id));
        $this->assertTrue($regionalLeader->canAccessBranch((int) $assignedBranch->id));
        $this->assertCount(2, $regionalLeader->applyAccessibleBranchScope(Branch::query(), 'id')->get());
    }

    #[Test]
    public function authenticated_model_queries_are_limited_to_accessible_branches(): void
    {
        $ownBranch = Branch::create([
            'code' => 'QUERY-01',
            'name' => 'Query Own Branch',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);
        $otherBranch = Branch::create([
            'code' => 'QUERY-02',
            'name' => 'Query Other Branch',
            'city' => 'Bandung',
            'status' => 'active',
        ]);

        foreach ([$ownBranch, $otherBranch] as $branch) {
            StockItem::create([
                'branch_id' => $branch->id,
                'cylinder_type' => '3kg',
                'qty_full' => 100,
                'qty_empty' => 20,
                'qty_damaged' => 0,
                'recorded_at' => today(),
            ]);
        }

        $user = User::create([
            'name' => 'Query Scope User',
            'email' => 'query-scope@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_cabang',
            'branch_id' => $ownBranch->id,
            'status' => 'active',
        ]);

        foreach ([null, $ownBranch->id, $otherBranch->id] as $branchId) {
            LpgPrice::create([
                'branch_id' => $branchId,
                'cylinder_type' => '3kg',
                'purchase_price' => 10000,
                'selling_price' => 12000,
                'effective_date' => today(),
                'created_by' => $user->id,
            ]);
        }

        $this->actingAs($user);

        $this->assertSame([$ownBranch->id], StockItem::query()->pluck('branch_id')->all());
        $this->assertSame(
            [null, $ownBranch->id],
            LpgPrice::query()->orderBy('id')->pluck('branch_id')->all()
        );

        $assignedRole = Role::create([
            'name' => 'query_scope_assigned',
            'label' => 'Query Scope Assigned',
            'data_scope' => Role::SCOPE_ASSIGNED_BRANCHES,
        ]);
        $assignedUser = User::create([
            'name' => 'Assigned Query User',
            'email' => 'assigned-query@example.test',
            'password' => bcrypt('password'),
            'role' => 'staff_gudang',
            'branch_id' => $ownBranch->id,
            'status' => 'active',
        ]);
        $assignedUser->accessRole()->associate($assignedRole);
        $assignedUser->save();
        $assignedUser->assignedBranches()->attach($otherBranch);

        $this->actingAs($assignedUser);

        $this->assertSame([$otherBranch->id], StockItem::query()->pluck('branch_id')->all());
        $this->assertSame(
            [null, $otherBranch->id],
            LpgPrice::query()->orderBy('id')->pluck('branch_id')->all()
        );
    }
}
