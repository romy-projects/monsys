<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 18 / T18-01 — single entry point for every password write.
 *
 * Administrators reset a password from Users → Reset Password; users rotate
 * their own from the "Change Password" page or `POST /auth/password`. Going
 * through one service keeps the audit trail, the forced-change flag, the
 * remember-me cookie and the API tokens in sync no matter which entry point
 * triggered the change. Plaintext passwords are never persisted or logged.
 */
class UserPasswordService
{
    /**
     * The deployment default, used when an administrator resets a password
     * without typing a replacement.
     */
    public function defaultPassword(): string
    {
        return (string) config('security.password_reset.default', 'password');
    }

    public function minimumLength(): int
    {
        return max(8, (int) config('security.password_reset.min_length', 8));
    }

    /**
     * Administrator reset. Returns the password that was applied so the caller
     * can disclose it once — it is never written to the audit trail.
     */
    public function reset(User $target, ?string $password = null, bool $mustChange = true, ?User $actor = null): string
    {
        $applied = ($password === null || $password === '')
            ? $this->defaultPassword()
            : $password;

        $target->forceFill([
            'password' => $applied,
            'must_change_password' => $mustChange,
        ]);
        $target->setRememberToken(Str::random(60));
        $target->save();

        $revoked = $this->revokeApiTokens($target);

        app(AuditLogger::class)->record(
            event: 'user.password_reset',
            description: "Password reset for {$target->email}",
            subject: $target,
            properties: [
                'used_default' => $applied === $this->defaultPassword(),
                'must_change_password' => $mustChange,
                'revoked_tokens' => $revoked,
            ],
            user: $actor,
        );

        return $applied;
    }

    /**
     * Self-service change. Returns false when the supplied current password
     * does not match, so callers can surface a field-level error.
     *
     * `$exceptTokenId` lets an API client keep the token it is currently using
     * (mobile app) while every other device is signed out.
     */
    public function change(User $user, string $currentPassword, string $newPassword, ?int $exceptTokenId = null): bool
    {
        if (! Hash::check($currentPassword, (string) $user->password)) {
            return false;
        }

        $user->forceFill([
            'password' => $newPassword,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
        $user->setRememberToken(Str::random(60));
        $user->save();

        $revoked = $this->revokeApiTokens($user, $exceptTokenId);

        app(AuditLogger::class)->record(
            event: 'user.password_changed',
            description: "Password changed by {$user->email}",
            subject: $user,
            properties: ['revoked_tokens' => $revoked],
            user: $user,
        );

        return true;
    }

    /**
     * Every credential issued before the change becomes useless: Sanctum
     * tokens are deleted here and the rotated remember_token kills the
     * "remember me" cookie. Browser sessions are invalidated by
     * AuthenticateSession, which compares the stored password hash.
     */
    public function revokeApiTokens(User $user, ?int $exceptTokenId = null): int
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return 0;
        }

        $query = $user->tokens();

        if ($exceptTokenId !== null) {
            $query->whereKeyNot($exceptTokenId);
        }

        return $query->delete();
    }
}
