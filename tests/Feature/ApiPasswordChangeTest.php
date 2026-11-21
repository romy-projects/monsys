<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 18 / T18-01 — password rotation through the API.
 */
class ApiPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function login_reports_a_pending_password_change(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['must_change_password' => true])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonPath('data.user.must_change_password', true);
    }

    #[Test]
    public function a_client_can_change_the_password_and_keeps_its_own_token(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['must_change_password' => true])->save();

        $token = $user->createToken('mobile')->plainTextToken;
        $user->createToken('tablet');

        $this->withToken($token)
            ->postJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'password' => 'ApiRotated#18',
                'password_confirmation' => 'ApiRotated#18',
            ])
            ->assertOk()
            ->assertJsonPath('data.must_change_password', false);

        $fresh = $user->fresh();

        $this->assertTrue(Hash::check('ApiRotated#18', $fresh->password));
        $this->assertFalse($fresh->must_change_password);
        $this->assertNotNull($fresh->password_changed_at);

        // Only the calling device survives.
        $this->assertSame(1, $fresh->tokens()->count());

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.password_changed',
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function a_wrong_current_password_is_rejected(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/password', [
                'current_password' => 'not-my-password',
                'password' => 'ApiRotated#18',
                'password_confirmation' => 'ApiRotated#18',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.current_password.0', 'The provided current password is incorrect.');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    #[Test]
    public function the_password_must_be_confirmed_and_short_values_are_rejected(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/password', [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    private function makeUser(): User
    {
        $branch = Branch::query()->firstOrCreate(
            ['code' => 'PWD-02'],
            ['name' => 'Api Password Branch', 'city' => 'Jakarta', 'status' => 'active'],
        );

        return User::create([
            'name' => 'Api Password User',
            'email' => 'api-password@example.test',
            'password' => bcrypt('password'),
            'role' => 'staff_gudang',
            'role_id' => Role::query()->where('name', 'staff_gudang')->value('id'),
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
    }
}
