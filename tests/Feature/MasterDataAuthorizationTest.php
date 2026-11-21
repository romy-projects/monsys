<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MasterDataAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function branch_api_lists_and_updates_only_branches_in_the_users_scope(): void
    {
        $ownBranch = $this->createBranch('API-OWN', 'Own Branch');
        $assignedBranch = $this->createBranch('API-ASSIGNED', 'Assigned Branch');
        $outsideBranch = $this->createBranch('API-OUTSIDE', 'Outside Branch');

        $role = Role::create([
            'name' => 'api_branch_manager',
            'label' => 'API Branch Manager',
            'data_scope' => Role::SCOPE_ASSIGNED_BRANCHES,
        ]);
        $role->permissions()->attach(
            Permission::query()->whereIn('key', ['branches.view', 'branches.update'])->pluck('id')
        );

        $user = User::create([
            'name' => 'Assigned Branch Manager',
            'email' => 'api-branch-manager@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_cabang',
            'branch_id' => $ownBranch->id,
            'status' => 'active',
        ]);
        $user->accessRole()->associate($role);
        $user->save();
        $user->assignedBranches()->attach([$assignedBranch->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/branches')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $assignedBranch->id);

        $this->putJson("/api/v1/branches/{$outsideBranch->id}", ['name' => 'Should Not Update'])
            ->assertForbidden();

        $this->putJson("/api/v1/branches/{$assignedBranch->id}", ['name' => 'Updated Assigned Branch'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Assigned Branch');
    }

    #[Test]
    public function master_data_api_requires_the_specific_permission(): void
    {
        $branch = $this->createBranch('API-READ', 'Read Branch');
        $user = User::create([
            'name' => 'No User Management Permission',
            'email' => 'api-no-users@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_cabang',
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/users')
            ->assertForbidden();
    }

    private function createBranch(string $code, string $name): Branch
    {
        return Branch::create([
            'code' => $code,
            'name' => $name,
            'city' => 'Jakarta',
            'status' => 'active',
        ]);
    }
}
