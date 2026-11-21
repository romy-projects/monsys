<?php

namespace Tests\Feature;

use App\Filament\Pages\MyBranchDashboard;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page as PanelPage;
use Filament\Resources\Resource as PanelResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dry run of the panel: every screen a login can reach must render.
 *
 * Behaviour tests never build a list page's table, so a crash raised while the
 * table renders — a bulk action evaluated with no selected records, for
 * example — only surfaces by clicking through the menu as that user. Each case
 * here signs in once per seeded role and walks that role's own screens.
 */
class AdminPanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::create([
            'code' => 'SMOKE-01',
            'name' => 'Smoke Test Branch',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function the_super_admin_renders_every_screen_of_the_panel(): void
    {
        $this->actingAs($this->makeSuperAdmin());

        $screens = $this->screensForActingUser();

        $this->assertGreaterThanOrEqual(30, count($screens), 'The panel should expose its pages and list screens.');

        foreach ($screens as $label => $url) {
            $response = $this->get($url);

            $this->assertTrue(
                $response->isSuccessful(),
                "{$label} ({$url}) answered HTTP {$response->getStatusCode()} for the Super Admin."
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function seededRoles(): array
    {
        return [
            'owner_pusat' => ['owner_pusat'],
            'regional_leader' => ['regional_leader'],
            'owner_cabang' => ['owner_cabang'],
            'staff_gudang' => ['staff_gudang'],
        ];
    }

    #[Test]
    #[DataProvider('seededRoles')]
    public function a_seeded_role_renders_its_screens_without_server_errors(string $role): void
    {
        $this->actingAs($this->makeUser($role));

        $this->get('/admin')->assertOk();

        $screens = $this->screensForActingUser();

        $this->assertNotEmpty($screens, "{$role} cannot open a single panel screen.");

        foreach ($screens as $label => $url) {
            $response = $this->get($url);

            $this->assertLessThan(
                500,
                $response->getStatusCode(),
                "{$role} got HTTP {$response->getStatusCode()} on {$label} ({$url})."
            );
        }
    }

    #[Test]
    public function the_branch_dashboard_reports_on_the_super_admin_hq_branch(): void
    {
        // The Super Admin posts at the head office, so the page has a branch.
        // Before HQ existed it had none and 500'd on the null branch id.
        $this->actingAs($this->makeSuperAdmin());

        $hq = Branch::hq();
        $this->assertNotNull($hq, 'Posting the Super Admin must provision the head office.');

        $this->assertTrue(MyBranchDashboard::canAccess());

        $this->get('/admin/my-branch-dashboard')
            ->assertOk()
            ->assertSee($hq->name);
    }

    #[Test]
    public function the_branch_dashboard_opens_for_a_branch_owner(): void
    {
        $this->actingAs($this->makeUser('owner_cabang'));

        $this->assertTrue(MyBranchDashboard::canAccess());
        $this->get('/admin/my-branch-dashboard')->assertOk();
    }

    #[Test]
    public function the_branch_dashboard_uses_the_only_assigned_branch(): void
    {
        // No post of its own, but exactly one branch to report on.
        $this->actingAs($this->makeAssignedBranchesUser());

        $this->assertTrue(MyBranchDashboard::canAccess());
        $this->get('/admin/my-branch-dashboard')->assertOk();
    }

    #[Test]
    public function the_branch_dashboard_stays_hidden_when_several_branches_are_assigned(): void
    {
        $supervisor = $this->makeAssignedBranchesUser();
        $supervisor->assignedBranches()->attach(Branch::create([
            'code' => 'SMOKE-02',
            'name' => 'Second Smoke Branch',
            'city' => 'Bandung',
            'status' => 'active',
        ]));

        $this->actingAs($supervisor->fresh());

        $this->assertFalse(MyBranchDashboard::canAccess());
        $this->get('/admin/my-branch-dashboard')->assertForbidden();
    }

    /**
     * Every page and list screen the acting user may open, keyed by a label.
     *
     * @return array<string, string>
     */
    private function screensForActingUser(): array
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);

        $screens = [];

        foreach ($panel->getPages() as $page) {
            if (! is_subclass_of($page, PanelPage::class) || ! $page::canAccess()) {
                continue;
            }

            $screens['page '.$page] = $page::getUrl();
        }

        foreach ($panel->getResources() as $resource) {
            /** @var class-string<PanelResource> $resource */
            if (! $resource::canAccess() || ! $resource::canViewAny()) {
                continue;
            }

            $screens['list '.$resource] = $resource::getUrl('index');
        }

        return $screens;
    }

    private function makeUser(string $role): User
    {
        $user = User::create([
            'name' => ucfirst(str_replace('_', ' ', $role)).' Smoke',
            'email' => "smoke-{$role}@example.test",
            'password' => bcrypt('password'),
            'role' => $role,
            // Only the regional leader is posted without a branch of its own.
            'branch_id' => $role === 'regional_leader' ? null : $this->branch->id,
            'status' => 'active',
        ]);

        if ($role === 'regional_leader') {
            $user->assignedBranches()->attach($this->branch);
        }

        return $user;
    }

    /**
     * A role that only sees explicitly assigned branches, with no own post.
     */
    private function makeAssignedBranchesUser(): User
    {
        $role = Role::create([
            'name' => 'area_supervisor',
            'label' => 'Area Supervisor',
            'description' => 'Assigned-branches role for the panel smoke test.',
            'data_scope' => Role::SCOPE_ASSIGNED_BRANCHES,
        ]);

        $role->permissions()->sync(
            Permission::query()->whereIn('key', ['dashboard.branch.view'])->pluck('id')->all()
        );

        $user = User::create([
            'name' => 'Area Supervisor Smoke',
            'email' => 'smoke-area-supervisor@example.test',
            'password' => bcrypt('password'),
            'role' => 'regional_leader',
            'branch_id' => null,
            'status' => 'active',
        ]);

        $user->accessRole()->associate($role);
        $user->save();
        $user->assignedBranches()->attach($this->branch);

        return $user->fresh();
    }

    /**
     * The hidden account at the top of the hierarchy: the legacy enum is kept at
     * `owner_pusat` and authorization runs on the Super Admin access role. It is
     * a global account that posts at HQ, exactly like the provisioned one.
     */
    private function makeSuperAdmin(): User
    {
        $user = User::create([
            'name' => 'Super Admin Smoke',
            'email' => 'smoke-super-admin@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'branch_id' => Branch::provisionHq()->id,
            'status' => 'active',
        ]);

        $user->accessRole()->associate(Role::query()->where('name', 'super_admin')->firstOrFail());
        $user->save();

        return $user->fresh();
    }
}
