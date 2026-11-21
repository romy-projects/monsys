<?php

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\ListUsers;
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
 * Phase 16A / T16-03 — an administrator can reset a user's second factor.
 */
class TwoFactorAdminResetTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_super_admin_can_reset_a_users_two_factor_and_it_is_audited(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();

        $actor = $this->makeUser('owner_pusat', 'reset-actor@example.test');
        $actor->accessRole()->associate(Role::query()->where('name', 'super_admin')->firstOrFail());
        $actor->save();

        $target = $this->makeUser('staff_gudang', 'reset-target@example.test');
        $this->enableTwoFactor($target);

        $this->actingAs($actor);

        Livewire::test(ListUsers::class)
            ->callTableAction('reset_two_factor', $target->id);

        $this->assertFalse($target->fresh()->hasTwoFactorEnabled());
        $this->assertNull($target->fresh()->twoFactorSecret());

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.two_factor_reset',
            'user_id' => $actor->id,
            'subject_type' => User::class,
            'subject_id' => $target->id,
        ]);
    }

    private function enableTwoFactor(User $user): void
    {
        $secret = (string) $user->beginTwoFactorEnrollment();

        $this->assertTrue($user->confirmTwoFactor((new Google2FA)->getCurrentOtp($secret)));
    }

    private function makeUser(string $legacyRole, string $email): User
    {
        $branch = Branch::query()->firstOrCreate(
            ['code' => 'TFA-05'],
            ['name' => 'Two Factor Reset Branch', 'city' => 'Jakarta', 'status' => 'active'],
        );

        return User::create([
            'name' => 'Two Factor Reset '.$legacyRole,
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => $legacyRole,
            'role_id' => Role::query()->where('name', $legacyRole)->value('id'),
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
    }
}
