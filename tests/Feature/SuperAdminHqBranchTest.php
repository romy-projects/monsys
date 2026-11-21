<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Super Admin posts at the head office.
 *
 * The account is a global one, but branch-scoped screens (the branch dashboard,
 * new documents) still need a single branch. HQ is that branch, and the post is
 * a default — never a restriction: the role keeps all-branch reach.
 */
class SuperAdminHqBranchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function creating_the_super_admin_provisions_the_head_office_and_posts_it_there(): void
    {
        $this->assertNull(Branch::hq(), 'HQ must not exist before a Super Admin is provisioned.');

        $this->createSuperAdmin();

        $hq = Branch::query()->where('code', Branch::HQ_CODE)->firstOrFail();

        $this->assertSame('active', $hq->status);
        $this->assertSame($hq->id, User::query()->findOrFail(0)->branch_id);
    }

    #[Test]
    public function the_head_office_is_provisioned_only_once(): void
    {
        $first = Branch::provisionHq();
        $second = Branch::provisionHq();

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Branch::query()->whereIn('code', Branch::HQ_CODES)->count());
    }

    #[Test]
    public function the_post_never_narrows_the_all_branch_reach(): void
    {
        $this->createSuperAdmin();

        $other = Branch::create([
            'code' => 'CBG-777',
            'name' => 'Branch Away From HQ',
            'city' => 'Bandung',
            'status' => 'active',
        ]);

        $superAdmin = User::query()->findOrFail(0);

        $this->assertTrue($superAdmin->canAccessAllBranches());
        $this->assertTrue($superAdmin->canAccessBranch((int) $other->id));
        $this->assertEqualsCanonicalizing(
            Branch::query()->pluck('id')->all(),
            $superAdmin->accessibleBranchIds()
        );
    }

    #[Test]
    public function the_branch_dashboard_reports_the_head_office_instead_of_crashing(): void
    {
        $this->createSuperAdmin();

        $superAdmin = User::query()->findOrFail(0);
        $superAdmin->forceFill(['must_change_password' => false])->save();

        $this->actingAs($superAdmin)
            ->get('/admin/my-branch-dashboard')
            ->assertOk()
            ->assertSee('Pusat / HQ');
    }

    #[Test]
    public function promoting_an_owner_moves_the_account_to_the_head_office(): void
    {
        $branch = Branch::create([
            'code' => 'CBG-001',
            'name' => 'Cabang Bandung',
            'city' => 'Bandung',
            'status' => 'active',
        ]);

        $user = User::create([
            'name' => 'Initial Admin',
            'email' => 'initial-admin@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);

        $this->artisan('access:bootstrap-super-admin', ['email' => $user->email])
            ->expectsQuestion('Re-enter the target email to confirm promotion', $user->email)
            ->assertExitCode(0);

        $user->refresh();

        $this->assertSame(Role::query()->where('is_super_admin', true)->value('id'), $user->role_id);
        $this->assertSame(Branch::query()->where('code', Branch::HQ_CODE)->value('id'), $user->branch_id);
    }

    #[Test]
    public function the_backfill_migration_posts_an_existing_super_admin_at_the_head_office(): void
    {
        // An account provisioned before the head office existed.
        $user = User::create([
            'name' => 'Legacy Super Admin',
            'email' => 'legacy-super-admin@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'branch_id' => null,
            'status' => 'active',
        ]);

        $user->accessRole()->associate(Role::query()->where('is_super_admin', true)->firstOrFail());
        $user->save();

        $migration = require database_path('migrations/2026_11_21_000600_assign_hq_branch_to_super_admin.php');
        $migration->up();

        $hq = Branch::query()->where('code', Branch::HQ_CODE)->firstOrFail();

        $this->assertSame($hq->id, $user->fresh()->branch_id);
    }

    private function createSuperAdmin(): void
    {
        $this->artisan('access:create-super-admin', [
            'email' => 'root@example.test',
            '--password' => 'Initial#Pass18',
        ])->assertExitCode(0);
    }
}
