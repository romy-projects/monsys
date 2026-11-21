<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Phase 16A / T16-03 — optional TOTP two-factor authentication (model layer).
 */
class TwoFactorOptionalTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function two_factor_authentication_is_off_by_default(): void
    {
        $user = $this->makeUser();

        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertNull($user->twoFactorSecret());
        $this->assertSame([], $user->recoveryCodes());
        $this->assertFalse($user->mustEnrollTwoFactor());
    }

    #[Test]
    public function an_enrolment_only_counts_once_it_is_confirmed_with_a_valid_code(): void
    {
        $user = $this->makeUser();

        $secret = $user->beginTwoFactorEnrollment();

        $this->assertNotEmpty($secret);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertFalse($user->confirmTwoFactor('000000'));

        $this->assertTrue($user->confirmTwoFactor($this->codeFor($secret)));
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->assertCount(8, $user->fresh()->recoveryCodes());
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    #[Test]
    public function the_secret_and_recovery_codes_are_encrypted_and_hidden(): void
    {
        $user = $this->makeUser();
        $this->enableTwoFactor($user);

        $rawSecret = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $rawCodes = DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');

        $this->assertNotSame($user->fresh()->twoFactorSecret(), $rawSecret);
        $this->assertNotSame(json_encode($user->fresh()->recoveryCodes()), $rawCodes);

        $array = $user->fresh()->toArray();
        $this->assertArrayNotHasKey('two_factor_secret', $array);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $array);
        $this->assertArrayNotHasKey('password', $array);
    }

    #[Test]
    public function a_recovery_code_works_exactly_once(): void
    {
        $user = $this->makeUser();
        $this->enableTwoFactor($user);

        $code = $user->fresh()->recoveryCodes()[0];

        $this->assertTrue($user->fresh()->consumeRecoveryCode(strtolower($code)));
        $this->assertCount(7, $user->fresh()->recoveryCodes());
        $this->assertFalse($user->fresh()->consumeRecoveryCode($code));
        $this->assertFalse($user->fresh()->consumeRecoveryCode('does-not-exist'));
    }

    #[Test]
    public function disabling_two_factor_clears_the_secret_and_the_codes(): void
    {
        $user = $this->makeUser();
        $secret = $this->enableTwoFactor($user);

        $user->fresh()->disableTwoFactor();

        $user->refresh();
        $this->assertFalse($user->hasTwoFactorEnabled());
        $this->assertNull($user->twoFactorSecret());
        $this->assertSame([], $user->recoveryCodes());
        $this->assertFalse($user->verifyTwoFactorCode($this->codeFor($secret)));
    }

    #[Test]
    public function enforcement_is_driven_by_the_configured_role_list(): void
    {
        $owner = $this->makeUser('owner_pusat', 'owner@example.test');
        $staff = $this->makeUser('staff_gudang', 'staff@example.test');

        $this->assertFalse($owner->mustEnrollTwoFactor());

        config(['security.two_factor.enforced_roles' => ['owner_pusat']]);

        $this->assertTrue($owner->fresh()->mustEnrollTwoFactor());
        $this->assertFalse($staff->fresh()->mustEnrollTwoFactor());

        $this->enableTwoFactor($owner);
        $this->assertFalse($owner->fresh()->mustEnrollTwoFactor());
    }

    private function codeFor(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    private function enableTwoFactor(User $user): string
    {
        $secret = (string) $user->beginTwoFactorEnrollment();

        $this->assertTrue($user->fresh()->confirmTwoFactor($this->codeFor($secret)));

        return $secret;
    }

    private function makeUser(string $legacyRole = 'staff_gudang', string $email = 'two-factor@example.test'): User
    {
        $branch = Branch::query()->firstOrCreate(
            ['code' => 'TFA-01'],
            ['name' => 'Two Factor Branch', 'city' => 'Jakarta', 'status' => 'active'],
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
