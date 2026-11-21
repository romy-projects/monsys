<?php

namespace Tests\Feature;

use App\Filament\Pages\ImpersonationAudit;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 17 / T17-05 + T17-06 — impersonation and its audit trail.
 */
class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $branchUser;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::create([
            'code' => 'IMP-01',
            'name' => 'Impersonation Branch',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);

        $this->superAdmin = $this->makeUser('owner_pusat', 'impersonator@example.test');
        $this->superAdmin->accessRole()->associate(Role::query()->where('name', 'super_admin')->firstOrFail());
        $this->superAdmin->save();

        $this->branchUser = $this->makeUser('owner_cabang', 'impersonated@example.test', $branch->id);
    }

    #[Test]
    public function a_super_admin_can_impersonate_an_active_user_and_stop_again(): void
    {
        $this->actingAs($this->superAdmin)
            ->post(route('impersonation.start', $this->branchUser), ['reason' => 'Support call'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($this->branchUser);
        $this->assertSame($this->superAdmin->id, session(ImpersonationService::SESSION_KEY));
        $this->assertSame('Support call', session(ImpersonationService::REASON_KEY));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'impersonation.started',
            'user_id' => $this->superAdmin->id,
            'subject_id' => $this->branchUser->id,
        ]);

        $this->post(route('impersonation.stop'))->assertRedirect();

        $this->assertAuthenticatedAs($this->superAdmin);
        $this->assertNull(session(ImpersonationService::SESSION_KEY));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'impersonation.stopped',
            'impersonator_id' => $this->superAdmin->id,
        ]);
    }

    #[Test]
    public function users_without_the_impersonation_permission_are_rejected(): void
    {
        $this->actingAs($this->branchUser)
            ->post(route('impersonation.start', $this->superAdmin))
            ->assertForbidden();

        $this->assertAuthenticatedAs($this->branchUser);
        $this->assertSame(0, AuditLog::query()->where('event', 'impersonation.started')->count());
    }

    #[Test]
    public function super_admin_self_and_inactive_accounts_cannot_be_impersonated(): void
    {
        $otherSuperAdmin = $this->makeUser('owner_pusat', 'second-super-admin@example.test');
        $otherSuperAdmin->accessRole()->associate(Role::query()->where('name', 'super_admin')->firstOrFail());
        $otherSuperAdmin->save();

        $this->actingAs($this->superAdmin);

        $this->post(route('impersonation.start', $otherSuperAdmin))->assertForbidden();
        $this->post(route('impersonation.start', $this->superAdmin))->assertForbidden();

        $this->branchUser->update(['status' => 'inactive']);
        $this->post(route('impersonation.start', $this->branchUser))->assertForbidden();

        $this->assertAuthenticatedAs($this->superAdmin);
        $this->assertSame(0, AuditLog::query()->where('event', 'impersonation.started')->count());
    }

    #[Test]
    public function state_changing_requests_while_impersonating_are_audited(): void
    {
        Route::middleware(['web', 'auth'])->post('/__tests/impersonated-action', fn () => response('ok'));

        $this->actingAs($this->branchUser)
            ->withSession([ImpersonationService::SESSION_KEY => $this->superAdmin->id])
            ->post('/__tests/impersonated-action')
            ->assertOk();

        $log = AuditLog::query()->where('event', 'impersonation.action')->firstOrFail();

        $this->assertSame($this->branchUser->id, $log->user_id);
        $this->assertSame($this->superAdmin->id, $log->impersonator_id);
        $this->assertSame('POST', $log->properties['method']);
    }

    #[Test]
    public function read_only_requests_while_impersonating_are_not_audited(): void
    {
        Route::middleware(['web', 'auth'])->get('/__tests/impersonated-read', fn () => response('ok'));

        $this->actingAs($this->branchUser)
            ->withSession([ImpersonationService::SESSION_KEY => $this->superAdmin->id])
            ->get('/__tests/impersonated-read')
            ->assertOk();

        $this->assertSame(0, AuditLog::query()->where('event', 'impersonation.action')->count());
    }

    #[Test]
    public function the_impersonation_banner_offers_a_way_back(): void
    {
        $html = view('filament.components.impersonation-banner', [
            'target' => $this->branchUser,
            'initiator' => $this->superAdmin,
            'reason' => 'Support call',
        ])->render();

        $this->assertStringContainsString('Impersonating', $html);
        $this->assertStringContainsString($this->branchUser->name, $html);
        $this->assertStringContainsString($this->superAdmin->name, $html);
        $this->assertStringContainsString('Support call', $html);
        $this->assertStringContainsString(route('impersonation.stop'), $html);
        $this->assertStringContainsString('Stop impersonating', $html);
    }

    #[Test]
    public function the_audit_page_renders_for_a_super_admin_and_is_closed_to_others(): void
    {
        AuditLog::create([
            'event' => 'impersonation.started',
            'description' => 'Support started impersonating a branch user',
            'user_id' => $this->superAdmin->id,
            'subject_type' => User::class,
            'subject_id' => $this->branchUser->id,
        ]);

        $this->actingAs($this->superAdmin);
        $this->assertTrue(ImpersonationAudit::canAccess());

        Livewire::test(ImpersonationAudit::class)
            ->assertOk()
            ->assertSee('impersonation.started')
            ->assertSee('Support started impersonating a branch user');

        $this->actingAs($this->branchUser);
        $this->assertFalse(ImpersonationAudit::canAccess());
    }

    #[Test]
    public function the_panel_shows_the_banner_while_impersonating(): void
    {
        $this->actingAs($this->branchUser)
            ->withSession([
                ImpersonationService::SESSION_KEY => $this->superAdmin->id,
                ImpersonationService::REASON_KEY => 'Support call',
            ])
            ->get('/admin')
            ->assertOk()
            ->assertSee('Stop impersonating')
            ->assertSee($this->branchUser->name);
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
}
