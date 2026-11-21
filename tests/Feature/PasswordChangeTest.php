<?php

namespace Tests\Feature;

use App\Filament\Pages\ChangePassword;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 18 / T18-01 — self-service password rotation from the panel.
 */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    #[Test]
    public function any_signed_in_user_can_open_the_change_password_page(): void
    {
        $user = $this->makeUser('staff_gudang', 'change-page@example.test');

        $this->actingAs($user)
            ->get(ChangePassword::getUrl(panel: 'admin'))
            ->assertOk();
    }

    #[Test]
    public function a_wrong_current_password_is_rejected_and_nothing_changes(): void
    {
        $user = $this->makeUser('staff_gudang', 'wrong-current@example.test');

        $this->actingAs($user);

        Livewire::test(ChangePassword::class)
            ->set('current_password', 'not-my-password')
            ->set('password', 'brand-new-secret')
            ->set('password_confirmation', 'brand-new-secret')
            ->call('submit')
            ->assertHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    #[Test]
    public function the_new_password_must_be_long_enough_and_confirmed(): void
    {
        $user = $this->makeUser('staff_gudang', 'confirm-guard@example.test');

        $this->actingAs($user);

        Livewire::test(ChangePassword::class)
            ->set('current_password', 'password')
            ->set('password', 'short')
            ->set('password_confirmation', 'mismatch')
            ->call('submit')
            ->assertHasErrors('password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    #[Test]
    public function a_successful_change_rehashes_audits_clears_the_flag_and_revokes_tokens(): void
    {
        $user = $this->makeUser('owner_cabang', 'rotate@example.test');
        $user->forceFill(['must_change_password' => true])->save();
        $user->createToken('mobile');
        $user->createToken('tablet');

        $this->actingAs($user->fresh());

        Livewire::test(ChangePassword::class)
            ->set('current_password', 'password')
            ->set('password', 'rotated-secret-18')
            ->set('password_confirmation', 'rotated-secret-18')
            ->call('submit')
            ->assertHasNoErrors();

        $fresh = $user->fresh();

        $this->assertTrue(Hash::check('rotated-secret-18', $fresh->password));
        $this->assertFalse($fresh->must_change_password);
        $this->assertNotNull($fresh->password_changed_at);
        $this->assertSame(0, $fresh->tokens()->count());

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.password_changed',
            'user_id' => $user->id,
            'subject_type' => User::class,
            'subject_id' => $user->id,
        ]);
    }

    #[Test]
    public function the_hidden_super_admin_can_rotate_its_own_password(): void
    {
        $this->artisan('access:create-super-admin', [
            'email' => 'root@example.test',
            '--password' => 'Initial#Pass18',
        ])->assertExitCode(0);

        $this->actingAs(User::query()->findOrFail(0));

        Livewire::test(ChangePassword::class)
            ->set('current_password', 'Initial#Pass18')
            ->set('password', 'Rotated#Pass18')
            ->set('password_confirmation', 'Rotated#Pass18')
            ->call('submit')
            ->assertHasNoErrors();

        $fresh = User::query()->findOrFail(0);

        $this->assertTrue(Hash::check('Rotated#Pass18', $fresh->password));
        $this->assertFalse($fresh->must_change_password);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.password_changed',
            'user_id' => 0,
            'subject_id' => 0,
        ]);
    }

    private function makeUser(string $legacyRole, string $email): User
    {
        return User::create([
            'name' => 'Password Change '.$legacyRole,
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => $legacyRole,
            'role_id' => Role::query()->where('name', $legacyRole)->value('id'),
            'branch_id' => null,
            'status' => 'active',
        ]);
    }
}
