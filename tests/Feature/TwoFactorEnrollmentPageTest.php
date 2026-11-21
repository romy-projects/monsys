<?php

namespace Tests\Feature;

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
 * Phase 16A / T16-03 — the self-service "Security" page.
 */
class TwoFactorEnrollmentPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();

        $this->user = $this->makeUser();
        $this->actingAs($this->user);
    }

    #[Test]
    public function a_user_can_enrol_confirm_and_receive_recovery_codes(): void
    {
        $component = Livewire::test(SecuritySettings::class)
            ->assertSet('enrolling', false)
            ->call('startEnrollment')
            ->assertSet('enrolling', true)
            ->assertSet('recoveryCodes', []);

        $secret = (string) $this->user->fresh()->twoFactorSecret();
        $this->assertNotEmpty($secret);
        $this->assertFalse($this->user->fresh()->hasTwoFactorEnabled());

        $component->set('code', '000000')->call('confirmEnrollment')->assertHasErrors('code');
        $this->assertFalse($this->user->fresh()->hasTwoFactorEnabled());

        $component->set('code', $this->codeFor($secret))->call('confirmEnrollment')->assertHasNoErrors();

        $this->assertTrue($this->user->fresh()->hasTwoFactorEnabled());
        $this->assertCount(8, $component->get('recoveryCodes'));
        $component->assertSet('enrolling', false);
    }

    #[Test]
    public function cancelling_an_enrolment_removes_the_pending_secret(): void
    {
        Livewire::test(SecuritySettings::class)
            ->call('startEnrollment')
            ->call('cancelEnrollment')
            ->assertSet('enrolling', false);

        $this->assertNull($this->user->fresh()->twoFactorSecret());
        $this->assertFalse($this->user->fresh()->hasTwoFactorEnabled());
    }

    #[Test]
    public function recovery_codes_can_be_regenerated_with_the_current_password(): void
    {
        $this->enableTwoFactor($this->user);

        $before = $this->user->fresh()->recoveryCodes();
        $component = Livewire::test(SecuritySettings::class);

        $component->set('password', 'wrong-password')->call('regenerateRecoveryCodes')->assertHasErrors('password');
        $this->assertSame($before, $this->user->fresh()->recoveryCodes());

        $component->set('password', 'password')->call('regenerateRecoveryCodes')->assertHasNoErrors();

        $this->assertNotSame($before, $this->user->fresh()->recoveryCodes());
        $this->assertCount(8, $component->get('recoveryCodes'));
    }

    #[Test]
    public function turning_two_factor_off_requires_the_current_password(): void
    {
        $this->enableTwoFactor($this->user);

        $component = Livewire::test(SecuritySettings::class);

        $component->set('password', 'wrong-password')->call('disableTwoFactorAuthentication')->assertHasErrors('password');
        $this->assertTrue($this->user->fresh()->hasTwoFactorEnabled());

        $component->set('password', 'password')->call('disableTwoFactorAuthentication')->assertHasNoErrors();
        $this->assertFalse($this->user->fresh()->hasTwoFactorEnabled());
    }

    private function codeFor(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    private function enableTwoFactor(User $user): void
    {
        $secret = (string) $user->beginTwoFactorEnrollment();

        $this->assertTrue($user->confirmTwoFactor($this->codeFor($secret)));
    }

    private function makeUser(): User
    {
        $branch = Branch::query()->firstOrCreate(
            ['code' => 'TFA-03'],
            ['name' => 'Two Factor Page Branch', 'city' => 'Jakarta', 'status' => 'active'],
        );

        return User::create([
            'name' => 'Two Factor Page User',
            'email' => 'page-2fa@example.test',
            'password' => bcrypt('password'),
            'role' => 'staff_gudang',
            'role_id' => Role::query()->where('name', 'staff_gudang')->value('id'),
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
    }
}
