<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BusinessApiPermissionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function stock_api_enforces_the_configured_permission_and_assigned_branch_scope(): void
    {
        $allowedBranch = $this->createBranch('API-ALLOWED');
        $outsideBranch = $this->createBranch('API-OUTSIDE');
        $user = $this->createUserWithPermissions(
            'stock_reader',
            $allowedBranch,
            ['stock.view'],
            Role::SCOPE_ASSIGNED_BRANCHES
        );
        $user->assignedBranches()->attach($allowedBranch->id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/stock')
            ->assertOk();

        $this->getJson('/api/v1/stock?branch_id='.$outsideBranch->id)
            ->assertForbidden();
    }

    #[Test]
    public function business_api_write_routes_require_the_matching_action_permission(): void
    {
        $branch = $this->createBranch('API-READ-ONLY');
        $user = $this->createUserWithPermissions(
            'business_reader',
            $branch,
            ['operational_costs.view', 'sales.view'],
            Role::SCOPE_OWN_BRANCH
        );

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/costs')
            ->assertOk();

        $this->postJson('/api/v1/costs', [])
            ->assertForbidden();

        $this->postJson('/api/v1/sales', [])
            ->assertForbidden();
    }

    #[Test]
    public function imports_require_both_import_and_target_resource_permissions(): void
    {
        $branch = $this->createBranch('API-IMPORT');
        $importOnlyUser = $this->createUserWithPermissions(
            'import_only',
            $branch,
            ['imports.run'],
            Role::SCOPE_ALL_BRANCHES
        );

        $this->actingAs($importOnlyUser, 'sanctum')
            ->postJson('/api/v1/import/branches')
            ->assertForbidden();

        $authorizedUser = $this->createUserWithPermissions(
            'branch_importer',
            $branch,
            ['imports.run', 'branches.create'],
            Role::SCOPE_ALL_BRANCHES
        );

        $this->actingAs($authorizedUser, 'sanctum')
            ->postJson('/api/v1/import/branches')
            ->assertUnprocessable();
    }

    private function createUserWithPermissions(
        string $roleName,
        Branch $branch,
        array $permissionKeys,
        string $dataScope
    ): User {
        $role = Role::create([
            'name' => $roleName,
            'label' => ucfirst(str_replace('_', ' ', $roleName)),
            'data_scope' => $dataScope,
        ]);
        $role->permissions()->attach(
            Permission::query()->whereIn('key', $permissionKeys)->pluck('id')
        );

        $user = User::create([
            'name' => 'API Test User',
            'email' => $roleName.'@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_cabang',
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
        $user->accessRole()->associate($role);
        $user->save();

        return $user;
    }

    private function createBranch(string $code): Branch
    {
        return Branch::create([
            'code' => $code,
            'name' => $code,
            'city' => 'Jakarta',
            'status' => 'active',
        ]);
    }
}
