<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Phase 16A / T16-03 — the API login honours an enrolled second factor.
 */
class TwoFactorApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_enrolled_user_must_send_a_one_time_password(): void
    {
        $user = $this->makeUser();
        $this->enableTwoFactor($user);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.two_factor_required.0', 'A two-factor authentication code is required.');
    }

    #[Test]
    public function an_invalid_one_time_password_is_rejected(): void
    {
        $user = $this->makeUser();
        $this->enableTwoFactor($user);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'one_time_password' => '000000',
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.one_time_password.0',
                'The provided two-factor authentication code is invalid.',
            );
    }

    #[Test]
    public function a_valid_one_time_password_returns_a_token(): void
    {
        $user = $this->makeUser();
        $secret = $this->enableTwoFactor($user);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'one_time_password' => (new Google2FA)->getCurrentOtp($secret),
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonStructure(['data' => ['token', 'token_type', 'expires_in', 'user']]);
    }

    #[Test]
    public function a_recovery_code_also_works_and_is_consumed(): void
    {
        $user = $this->makeUser();
        $this->enableTwoFactor($user);

        $recoveryCode = $user->fresh()->recoveryCodes()[0];

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'one_time_password' => $recoveryCode,
        ])->assertOk();

        $this->assertCount(7, $user->fresh()->recoveryCodes());
    }

    #[Test]
    public function users_without_two_factor_still_log_in_with_email_and_password(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
    }

    private function enableTwoFactor(User $user): string
    {
        $secret = (string) $user->beginTwoFactorEnrollment();

        $this->assertTrue($user->confirmTwoFactor((new Google2FA)->getCurrentOtp($secret)));

        return $secret;
    }

    private function makeUser(): User
    {
        $branch = Branch::query()->firstOrCreate(
            ['code' => 'TFA-04'],
            ['name' => 'Two Factor Api Branch', 'city' => 'Jakarta', 'status' => 'active'],
        );

        return User::create([
            'name' => 'Two Factor Api User',
            'email' => 'api-2fa@example.test',
            'password' => bcrypt('password'),
            'role' => 'staff_gudang',
            'role_id' => Role::query()->where('name', 'staff_gudang')->value('id'),
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
    }
}
