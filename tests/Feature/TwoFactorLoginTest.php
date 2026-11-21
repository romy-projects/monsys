<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\SecuritySettings;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Phase 16A / T16-03 — the panel login only asks for a code when the user
 * enrolled, and enforcement stays opt-in through configuration.
 */
class TwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    #[Test]
    public function users_without_two_factor_log_in_with_their_password(): void
    {
        $user = $this->makeUser();

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function the_password_alone_does_not_log_an_enrolled_user_in(): void
    {
        $user = $this->makeUser();
        $this->enableTwoFactor($user);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertSet('challengeUserId', $user->id);

        $this->assertGuest();
    }

    #[Test]
    public function a_valid_authenticator_code_completes_the_login(): void
    {
        $user = $this->makeUser();
        $secret = $this->enableTwoFactor($user);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->set('data.code', $this->codeFor($secret))
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function a_wrong_code_is_rejected_and_the_user_stays_a_guest(): void
    {
        $user = $this->makeUser();
        $this->enableTwoFactor($user);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->set('data.code', '000000')
            ->call('authenticate')
            ->assertHasErrors('data.code');

        $this->assertGuest();
    }

    #[Test]
    public function a_recovery_code_can_be_used_instead_of_the_app(): void
    {
        $user = $this->makeUser();
        $this->enableTwoFactor($user);

        $recoveryCode = $user->fresh()->recoveryCodes()[0];

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->set('data.code', $recoveryCode)
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertCount(7, $user->fresh()->recoveryCodes());
    }

    #[Test]
    public function enforcement_redirects_an_unenrolled_user_to_the_security_page(): void
    {
        config(['security.two_factor.enforced_roles' => ['owner_pusat']]);

        $owner = $this->makeUser('owner_pusat', 'owner@example.test');

        $this->actingAs($owner)
            ->get('/admin')
            ->assertRedirect(SecuritySettings::getUrl(panel: 'admin'));

        $this->actingAs($owner)
            ->get(SecuritySettings::getUrl(panel: 'admin'))
            ->assertOk();
    }

    #[Test]
    public function enforcement_stops_once_the_user_has_enrolled(): void
    {
        config(['security.two_factor.enforced_roles' => ['owner_pusat']]);

        $owner = $this->makeUser('owner_pusat', 'owner@example.test');
        $this->enableTwoFactor($owner);

        $this->actingAs($owner)
            ->get('/admin')
            ->assertOk();
    }

    private function codeFor(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    private function enableTwoFactor(User $user): string
    {
        $secret = (string) $user->beginTwoFactorEnrollment();

        $this->assertTrue($user->confirmTwoFactor($this->codeFor($secret)));

        return $secret;
    }

    private function makeUser(string $legacyRole = 'staff_gudang', string $email = 'login-2fa@example.test'): User
    {
        $branch = Branch::query()->firstOrCreate(
            ['code' => 'TFA-02'],
            ['name' => 'Two Factor Login Branch', 'city' => 'Jakarta', 'status' => 'active'],
        );

        return User::create([
            'name' => 'Two Factor '.$legacyRole,
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => $legacyRole,
            'role_id' => Role::query()->where('name', $legacyRole)->value('id'),
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
    }
}
