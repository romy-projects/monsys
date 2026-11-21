<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 17 / N-17-01 — the dual role field: `users.role_id` is the authoritative
 * access role, `users.role` stays in sync for API and mobile clients.
 */
class UserRoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_explicit_access_role_survives_user_creation(): void
    {
        $customRole = $this->makeCustomRole();

        $user = User::create([
            'name' => 'Ops Lead',
            'email' => 'ops-lead@example.test',
            'password' => bcrypt('password'),
            'role' => 'staff_gudang',
            'role_id' => $customRole->id,
            'status' => 'active',
        ]);

        $this->assertSame($customRole->id, $user->fresh()->role_id);
        $this->assertSame('staff_gudang', $user->fresh()->role);
    }

    #[Test]
    public function the_legacy_role_still_populates_the_access_role(): void
    {
        $user = $this->makeUser('regional_leader', 'legacy-regional@example.test');

        $this->assertSame(
            (int) Role::query()->where('name', 'regional_leader')->value('id'),
            (int) $user->fresh()->role_id
        );
    }

    #[Test]
    public function changing_the_legacy_role_resyncs_the_access_role(): void
    {
        $user = $this->makeUser('owner_cabang', 'legacy-change@example.test');

        $user->update(['role' => 'staff_gudang']);

        $this->assertSame(
            (int) Role::query()->where('name', 'staff_gudang')->value('id'),
            (int) $user->fresh()->role_id
        );
    }

    #[Test]
    public function the_resource_helpers_map_built_in_roles_and_scopes(): void
    {
        $ownerCabangId = (int) Role::query()->where('name', 'owner_cabang')->value('id');
        $superAdminId = (int) Role::query()->where('name', 'super_admin')->value('id');
        $customRole = $this->makeCustomRole();

        $this->assertSame('owner_cabang', UserResource::builtInRoleName($ownerCabangId));
        $this->assertNull(UserResource::builtInRoleName($superAdminId));
        $this->assertNull(UserResource::builtInRoleName($customRole->id));
        $this->assertNull(UserResource::builtInRoleName(null));

        $this->assertSame(Role::SCOPE_OWN_BRANCH, UserResource::accessRoleScope($ownerCabangId));
        $this->assertSame(Role::SCOPE_ALL_BRANCHES, UserResource::accessRoleScope($superAdminId));
        $this->assertNull(UserResource::accessRoleScope('not-an-id'));

        $this->assertSame(UserResource::LEGACY_ROLES, array_keys(Role::CAPABILITY_PERMISSIONS));
    }

    #[Test]
    public function impersonation_targets_are_limited_to_active_non_super_admin_accounts(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $branchUser = $this->makeUser('owner_cabang', 'target-user@example.test');

        $this->assertTrue($branchUser->canBeImpersonatedBy($superAdmin));
        $this->assertFalse($superAdmin->canBeImpersonatedBy($superAdmin));
        $this->assertFalse($branchUser->canBeImpersonatedBy($branchUser));

        $branchUser->update(['status' => 'inactive']);

        $this->assertFalse($branchUser->fresh()->canBeImpersonatedBy($superAdmin));
    }

    #[Test]
    public function super_admin_accounts_cannot_be_edited_or_deleted_through_the_resource(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $branchUser = $this->makeUser('owner_cabang', 'editable-user@example.test', $this->makeBranch()->id);

        $this->actingAs($superAdmin);

        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertFalse(UserResource::canEdit($superAdmin));
        $this->assertFalse(UserResource::canDelete($superAdmin));
        $this->assertTrue(UserResource::canEdit($branchUser->fresh()));
    }

    #[Test]
    public function the_user_form_accepts_a_custom_access_role_and_keeps_it(): void
    {
        $this->actingAs($this->makeSuperAdmin());

        $customRole = $this->makeCustomRole();
        $branch = $this->makeBranch();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Area Lead User',
                'email' => 'area-lead@example.test',
                'password' => 'password123',
                'role_id' => $customRole->id,
                'role' => 'staff_gudang',
                'branch_id' => $branch->id,
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'area-lead@example.test')->firstOrFail();

        $this->assertSame($customRole->id, $user->role_id);
        $this->assertSame('staff_gudang', $user->role);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.access_role_changed',
            'subject_id' => $user->id,
        ]);
    }

    #[Test]
    public function the_user_form_refuses_to_grant_the_super_admin_role(): void
    {
        $this->actingAs($this->makeSuperAdmin());

        $superAdminRoleId = (int) Role::query()->where('name', 'super_admin')->value('id');
        $branch = $this->makeBranch();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Sneaky Admin',
                'email' => 'sneaky-admin@example.test',
                'password' => 'password123',
                'role_id' => $superAdminRoleId,
                'role' => 'owner_pusat',
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasFormErrors(['role_id']);

        $this->assertDatabaseMissing('users', ['email' => 'sneaky-admin@example.test']);
    }

    #[Test]
    public function editing_a_user_switches_the_access_role_and_audits_the_change(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $this->actingAs($superAdmin);

        $branch = $this->makeBranch();
        $customRole = $this->makeCustomRole();
        $user = $this->makeUser('staff_gudang', 'switch-role@example.test', $branch->id);

        $previousRoleId = (int) $user->role_id;

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm(['role_id' => $customRole->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame($customRole->id, $user->role_id);
        $this->assertNotSame($previousRoleId, (int) $user->role_id);

        $log = AuditLog::query()
            ->where('event', 'user.access_role_changed')
            ->where('subject_id', $user->id)
            ->firstOrFail();

        $this->assertSame($previousRoleId, $log->properties['from_role_id']);
        $this->assertSame($customRole->id, $log->properties['to_role_id']);
    }

    private function makeBranch(): Branch
    {
        static $sequence = 0;
        $sequence++;

        return Branch::create([
            'code' => 'URA-'.$sequence,
            'name' => 'User Role Branch '.$sequence,
            'city' => 'Jakarta',
            'status' => 'active',
        ]);
    }

    private function makeCustomRole(): Role
    {
        return Role::create([
            'name' => 'area_lead',
            'label' => 'Area Lead',
            'description' => 'Custom role for tests.',
            'data_scope' => Role::SCOPE_OWN_BRANCH,
        ]);
    }

    private function makeUser(string $legacyRole, string $email, ?int $branchId = null): User
    {
        return User::create([
            'name' => 'Test '.$legacyRole,
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => $legacyRole,
            'branch_id' => $branchId,
            'status' => 'active',
        ]);
    }

    private function makeSuperAdmin(): User
    {
        $user = $this->makeUser('owner_pusat', 'user-role-admin@example.test');
        $user->accessRole()->associate(Role::query()->where('name', 'super_admin')->firstOrFail());
        $user->save();

        return $user->fresh();
    }
}
