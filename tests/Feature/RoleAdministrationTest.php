<?php

namespace Tests\Feature;

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 17 / T17-04 — access role administration (T17-07 coverage).
 */
class RoleAdministrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function only_access_control_managers_can_open_role_administration(): void
    {
        $ownerPusat = $this->makeUser('owner_pusat', 'plain-owner@example.test');

        $this->actingAs($ownerPusat);
        $this->assertFalse(RoleResource::canAccess());
        $this->assertFalse(RoleResource::canCreate());

        $this->actingAs($this->makeSuperAdmin());
        $this->assertTrue(RoleResource::canAccess());
        $this->assertTrue(RoleResource::canCreate());
        $this->assertTrue(RoleResource::canEdit(Role::query()->where('name', 'owner_cabang')->firstOrFail()));
    }

    #[Test]
    public function built_in_roles_cannot_be_deleted_renamed_or_demoted(): void
    {
        $superAdminRole = Role::query()->where('name', 'super_admin')->firstOrFail();

        $this->assertFalse($superAdminRole->canBeDeleted());
        $this->assertFalse($superAdminRole->delete());

        $superAdminRole->update([
            'name' => 'renamed_super_admin',
            'is_active' => false,
            'data_scope' => Role::SCOPE_OWN_BRANCH,
        ]);

        $superAdminRole->refresh();

        $this->assertSame('super_admin', $superAdminRole->name);
        $this->assertTrue($superAdminRole->is_active);
        $this->assertSame(Role::SCOPE_ALL_BRANCHES, $superAdminRole->data_scope);

        $ownerCabang = Role::query()->where('name', 'owner_cabang')->firstOrFail();
        $this->assertFalse($ownerCabang->delete());
        $this->assertFalse($ownerCabang->canBeDeleted());

        $ownerCabang->update(['name' => 'hacked_name']);
        $this->assertSame('owner_cabang', $ownerCabang->fresh()->name);
    }

    #[Test]
    public function the_super_admin_role_can_never_be_edited_or_deleted_from_the_resource(): void
    {
        $this->actingAs($this->makeSuperAdmin());

        $superAdminRole = Role::query()->where('name', 'super_admin')->firstOrFail();

        $this->assertFalse(RoleResource::canEdit($superAdminRole));
        $this->assertFalse(RoleResource::canDelete($superAdminRole));
    }

    #[Test]
    public function the_role_list_renders_for_a_super_admin(): void
    {
        $this->actingAs($this->makeSuperAdmin());

        Livewire::test(ListRoles::class)
            ->assertOk()
            ->assertCanSeeTableRecords(Role::query()->get());
    }

    #[Test]
    public function a_custom_role_can_be_created_with_permissions_and_is_audited(): void
    {
        $this->actingAs($this->makeSuperAdmin());

        $permissionIds = Permission::query()
            ->whereIn('key', ['reports.profit_loss.view', 'stock.view'])
            ->pluck('id')
            ->all();

        Livewire::test(CreateRole::class)
            ->fillForm([
                'label' => 'Area Coordinator',
                'name' => 'area_coordinator',
                'description' => 'Coordinates a group of branches.',
                'data_scope' => Role::SCOPE_ASSIGNED_BRANCHES,
                'is_active' => true,
                'permissions' => $permissionIds,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::query()->where('name', 'area_coordinator')->firstOrFail();

        $this->assertFalse($role->is_system);
        $this->assertSame(Role::SCOPE_ASSIGNED_BRANCHES, $role->data_scope);
        $this->assertCount(2, $role->permissions);
        $this->assertTrue($role->isAssignable());
        $this->assertTrue(Role::query()->assignable()->whereKey($role->id)->exists());
        $this->assertDatabaseHas('audit_logs', ['event' => 'role.created']);
    }

    #[Test]
    public function built_in_roles_must_keep_their_capability_permission(): void
    {
        $this->actingAs($this->makeSuperAdmin());

        $role = Role::query()->where('name', 'owner_cabang')->firstOrFail();
        $capabilityId = (int) Permission::query()->where('key', 'system.branch_owner')->value('id');

        $remainingIds = $role->permissions()
            ->pluck('permissions.id')
            ->map(fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === $capabilityId)
            ->values()
            ->all();

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['permissions' => $remainingIds])
            ->call('save')
            ->assertHasFormErrors(['permissions']);

        $this->assertTrue($role->fresh()->permissions()->where('key', 'system.branch_owner')->exists());
    }

    #[Test]
    public function editing_a_built_in_role_keeps_its_capability_and_is_audited(): void
    {
        $this->actingAs($this->makeSuperAdmin());

        $role = Role::query()->where('name', 'staff_gudang')->firstOrFail();

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['label' => 'Warehouse Staff'])
            ->call('save')
            ->assertHasNoFormErrors();

        $role->refresh();

        $this->assertSame('Warehouse Staff', $role->label);
        $this->assertSame('staff_gudang', $role->name);
        $this->assertTrue($role->permissions()->where('key', 'system.warehouse_staff')->exists());
        $this->assertDatabaseHas('audit_logs', ['event' => 'role.updated', 'subject_id' => $role->id]);
    }

    private function makeUser(string $legacyRole, string $email): User
    {
        return User::create([
            'name' => 'Test '.$legacyRole,
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => $legacyRole,
            'status' => 'active',
        ]);
    }

    private function makeSuperAdmin(): User
    {
        $user = $this->makeUser('owner_pusat', 'role-admin@example.test');
        $user->accessRole()->associate(Role::query()->where('name', 'super_admin')->firstOrFail());
        $user->save();

        return $user->fresh();
    }
}
