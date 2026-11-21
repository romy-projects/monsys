<?php

namespace Tests\Feature;

use App\Filament\Pages\ChangePassword;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 18 / T18-01 — the forced rotation gate.
 */
class ForcePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    #[Test]
    public function a_flagged_user_is_redirected_to_the_change_password_page(): void
    {
        $user = $this->makeUser('owner_cabang');

        $this->actingAs($user)
            ->get('/admin')
            ->assertRedirect(ChangePassword::getUrl(panel: 'admin'));
    }

    #[Test]
    public function the_change_password_page_and_logout_stay_reachable_while_flagged(): void
    {
        $user = $this->makeUser('owner_cabang');

        $this->actingAs($user)
            ->get(ChangePassword::getUrl(panel: 'admin'))
            ->assertOk();

        $this->actingAs($user)
            ->post(route('filament.admin.auth.logout'))
            ->assertRedirect();
    }

    #[Test]
    public function a_json_request_is_answered_with_423_instead_of_a_redirect(): void
    {
        $user = $this->makeUser('owner_cabang');

        $this->actingAs($user)
            ->getJson('/admin')
            ->assertStatus(423)
            ->assertJsonPath('must_change_password', true);
    }

    #[Test]
    public function a_flagged_user_who_must_also_enrol_two_factor_is_not_looped(): void
    {
        config(['security.two_factor.enforced_roles' => ['owner_cabang']]);

        $user = $this->makeUser('owner_cabang');

        // Both middlewares leave the page open, so enrolment can happen after
        // the password has been rotated instead of bouncing between the two.
        $this->actingAs($user)
            ->get(ChangePassword::getUrl(panel: 'admin'))
            ->assertOk();
    }

    #[Test]
    public function the_dashboard_loads_once_the_password_has_been_rotated(): void
    {
        $user = $this->makeUser('owner_cabang');

        $this->actingAs($user);

        Livewire::test(ChangePassword::class)
            ->set('current_password', 'password')
            ->set('password', 'rotated-secret-18')
            ->set('password_confirmation', 'rotated-secret-18')
            ->call('submit')
            ->assertHasNoErrors();

        $this->get('/admin')->assertOk();
    }

    #[Test]
    public function an_unflagged_user_is_not_intercepted(): void
    {
        $user = $this->makeUser('owner_cabang', flagged: false);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk();
    }

    private function makeUser(string $legacyRole, bool $flagged = true): User
    {
        $user = User::create([
            'name' => 'Forced Rotation '.$legacyRole,
            'email' => 'forced-'.$legacyRole.'@example.test',
            'password' => bcrypt('password'),
            'role' => $legacyRole,
            'role_id' => Role::query()->where('name', $legacyRole)->value('id'),
            'branch_id' => null,
            'status' => 'active',
        ]);

        $user->forceFill(['must_change_password' => $flagged])->save();

        return $user;
    }
}
