<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BootstrapSuperAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_promotes_a_confirmed_active_owner_pusat_once(): void
    {
        $user = User::create([
            'name' => 'Initial Admin',
            'email' => 'initial-admin@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'status' => 'active',
        ]);

        $this->artisan('access:bootstrap-super-admin', ['email' => $user->email])
            ->expectsQuestion('Re-enter the target email to confirm promotion', $user->email)
            ->assertExitCode(0);

        $this->assertSame(
            Role::query()->where('is_super_admin', true)->value('id'),
            $user->fresh()->role_id
        );
        $this->assertSame('owner_pusat', $user->fresh()->role);

        $this->artisan('access:bootstrap-super-admin', ['email' => $user->email])
            ->assertExitCode(1);
    }

    #[Test]
    public function it_does_not_promote_an_unconfirmed_or_inactive_account(): void
    {
        $user = User::create([
            'name' => 'Inactive Admin',
            'email' => 'inactive-admin@example.test',
            'password' => bcrypt('password'),
            'role' => 'owner_pusat',
            'status' => 'inactive',
        ]);

        $this->artisan('access:bootstrap-super-admin', ['email' => $user->email])
            ->assertExitCode(1);
        $this->assertSame(
            Role::query()->where('name', 'owner_pusat')->value('id'),
            $user->fresh()->role_id
        );

        $user->update(['status' => 'active']);
        $this->artisan('access:bootstrap-super-admin', ['email' => $user->email])
            ->expectsQuestion('Re-enter the target email to confirm promotion', 'wrong@example.test')
            ->assertExitCode(1);

        $this->assertSame(
            Role::query()->where('name', 'owner_pusat')->value('id'),
            $user->fresh()->role_id
        );
    }
}
