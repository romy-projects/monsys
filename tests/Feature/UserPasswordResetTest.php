<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\ChangePassword;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 18 / T18-02 — administrator password reset, the protected Super Admin
 * account, and its invisibility in the user list.
 */
class UserPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $ownerPusat;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();

        $this->branch = Branch::create([
            'code' => 'PWD-01',
            'name' => 'Password Reset Branch',
            'city' => 'Jakarta',
            'status' => 'active',
        ]);

        $this->ownerPusat = $this->makeUser('owner_pusat', 'owner@example.test', null);
    }

    #[Test]
    public function an_administrator_resets_a_user_to_the_deployment_default(): void
    {
        $target = $this->makeUser('staff_gudang', 'reset-target@example.test', $this->branch->id);
        $target->createToken('old-phone');

        $this->actingAs($this->ownerPusat);

        Livewire::test(ListUsers::class)
            ->callTableAction('reset_password', $target, [
                'password' => config('security.password_reset.default'),
                'must_change_password' => true,
            ]);

        $fresh = $target->fresh();

        $this->assertTrue(Hash::check('password', $fresh->password));
        $this->assertTrue($fresh->must_change_password);
        $this->assertSame(0, $fresh->tokens()->count());

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.password_reset',
            'user_id' => $this->ownerPusat->id,
            'subject_type' => User::class,
            'subject_id' => $target->id,
        ]);

        $log = AuditLog::query()->where('event', 'user.password_reset')->firstOrFail();
        $this->assertTrue($log->properties['used_default']);
        $this->assertTrue($log->properties['must_change_password']);
        $this->assertSame(1, $log->properties['revoked_tokens']);
    }

    #[Test]
    public function a_typed_password_is_honoured_and_never_stored_in_the_audit_trail(): void
    {
        $target = $this->makeUser('staff_gudang', 'custom-target@example.test', $this->branch->id);
        $secret = 'Zx9#handoverSecret';

        $this->actingAs($this->ownerPusat);

        Livewire::test(ListUsers::class)
            ->callTableAction('reset_password', $target, [
                'password' => $secret,
                'must_change_password' => false,
            ]);

        $fresh = $target->fresh();

        $this->assertTrue(Hash::check($secret, $fresh->password));
        $this->assertFalse($fresh->must_change_password);

        $log = AuditLog::query()->where('event', 'user.password_reset')->firstOrFail();

        $this->assertFalse($log->properties['used_default']);
        $this->assertStringNotContainsString($secret, (string) json_encode($log->toArray()));
    }

    #[Test]
    public function bulk_reset_returns_every_selected_user_to_the_default(): void
    {
        $first = $this->makeUser('staff_gudang', 'bulk-one@example.test', $this->branch->id);
        $second = $this->makeUser('owner_cabang', 'bulk-two@example.test', $this->branch->id);

        $this->actingAs($this->ownerPusat);

        Livewire::test(ListUsers::class)
            ->callTableBulkAction('reset_password', [$first, $second]);

        foreach ([$first, $second] as $user) {
            $fresh = $user->fresh();

            $this->assertTrue(Hash::check((string) config('security.password_reset.default'), $fresh->password));
            $this->assertTrue($fresh->must_change_password);
        }
    }

    #[Test]
    public function a_user_without_the_users_update_permission_cannot_reset_anybody(): void
    {
        $staff = $this->makeUser('staff_gudang', 'no-rights@example.test', $this->branch->id);
        $target = $this->makeUser('staff_gudang', 'no-rights-target@example.test', $this->branch->id);

        $this->actingAs($staff);

        $this->assertFalse(UserResource::canResetPassword($target));
    }

    #[Test]
    public function the_super_admin_record_is_hidden_from_the_list_and_cannot_be_reset(): void
    {
        $this->artisan('access:create-super-admin', [
            'email' => 'root@example.test',
            '--password' => 'Initial#Pass18',
        ])->assertExitCode(0);

        $superAdmin = User::query()->findOrFail(0);

        $this->actingAs($this->ownerPusat);

        // Not listed, therefore not editable and not resettable from the UI.
        $this->assertFalse(UserResource::getEloquentQuery()->whereKey(0)->exists());
        $this->assertFalse(UserResource::canResetPassword($superAdmin));
        $this->assertTrue($superAdmin->isProtectedSystemAccount());

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$this->ownerPusat]);
    }

    #[Test]
    public function the_hidden_super_admin_signs_into_the_panel_like_any_other_account(): void
    {
        $this->artisan('access:create-super-admin', [
            'email' => 'root@example.test',
            '--password' => 'Initial#Pass18',
        ])->assertExitCode(0);

        $superAdmin = User::query()->findOrFail(0);
        $superAdmin->forceFill(['must_change_password' => false])->save();

        $this->actingAs($superAdmin)
            ->get('/admin')
            ->assertOk();
    }

    #[Test]
    public function the_hidden_super_admin_completes_a_real_login_and_is_forced_to_rotate(): void
    {
        $this->artisan('access:create-super-admin', [
            'email' => 'root@example.test',
            '--password' => 'Initial#Pass18',
        ])->assertExitCode(0);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'root@example.test',
                'password' => 'Initial#Pass18',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        // SessionGuard stores the identifier 0; a falsy check would log the
        // account straight back out, so this is asserted explicitly.
        $this->assertTrue(Auth::check());
        $this->assertSame(0, Auth::id());

        $this->get('/admin')
            ->assertRedirect(ChangePassword::getUrl(panel: 'admin'));
    }

    private function makeUser(string $legacyRole, string $email, ?int $branchId): User
    {
        return User::create([
            'name' => 'Password Reset '.$legacyRole,
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => $legacyRole,
            'role_id' => Role::query()->where('name', $legacyRole)->value('id'),
            'branch_id' => $branchId,
            'status' => 'active',
        ]);
    }
}
