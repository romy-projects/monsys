<?php

namespace App\Filament\Pages;

use App\Services\AuditLogger;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 16A / T16-03 — self-service security settings.
 *
 * Lets any signed-in user opt in to TOTP two-factor authentication, view the
 * one-time recovery codes and turn it off again. Nothing here is mandatory
 * unless the deployment lists the user's role in TWO_FACTOR_ENFORCED_ROLES.
 */
class SecuritySettings extends Page
{
    protected static string $view = 'filament.pages.security-settings';

    protected static ?string $navigationIcon = null;

    protected static ?string $title = 'Security';

    protected static ?string $slug = 'security-settings';

    protected static bool $shouldRegisterNavigation = false;

    public bool $enrolling = false;

    public ?string $secret = null;

    public ?string $qrSvg = null;

    public string $code = '';

    /** @var array<int, string> */
    public array $recoveryCodes = [];

    public string $password = '';

    public function mount(): void
    {
        $this->resetEnrollmentState();
    }

    public function startEnrollment(): void
    {
        $user = auth()->user();

        if ($user === null || $user->hasTwoFactorEnabled()) {
            return;
        }

        $this->secret = $user->beginTwoFactorEnrollment();
        $this->qrSvg = $user->fresh()->twoFactorQrCodeSvg();
        $this->enrolling = true;
        $this->code = '';
        $this->recoveryCodes = [];
    }

    public function confirmEnrollment(): void
    {
        $this->validate(['code' => ['required', 'string', 'max:32']]);

        $user = auth()->user();

        if ($user === null) {
            return;
        }

        if ($user->hasTwoFactorEnabled()) {
            $this->resetEnrollmentState();

            return;
        }

        if (! $user->confirmTwoFactor($this->code)) {
            $this->addError('code', 'That code is not valid. Check your phone clock and try the current code.');

            return;
        }

        $this->recoveryCodes = $user->fresh()->recoveryCodes();
        $this->resetEnrollmentState();

        app(AuditLogger::class)->record(
            'user.two_factor_enabled',
            "Two-factor authentication enabled for {$user->email}",
            $user,
        );

        Notification::make()
            ->title('Two-factor authentication enabled')
            ->body('Store the recovery codes in a safe place. Each one works only once.')
            ->success()
            ->send();
    }

    public function cancelEnrollment(): void
    {
        $user = auth()->user();

        if ($user !== null && ! $user->hasTwoFactorEnabled()) {
            $user->disableTwoFactor();
        }

        $this->resetEnrollmentState();
    }

    public function regenerateRecoveryCodes(): void
    {
        $this->validate(['password' => ['required', 'string']]);

        $user = auth()->user();

        if ($user === null || ! $this->passwordMatches($user->password)) {
            return;
        }

        $user->forceFill(['two_factor_recovery_codes' => $user->generateRecoveryCodes()])->save();

        $this->recoveryCodes = $user->fresh()->recoveryCodes();
        $this->password = '';

        app(AuditLogger::class)->record(
            'user.two_factor_recovery_codes_regenerated',
            "Two-factor recovery codes regenerated for {$user->email}",
            $user,
        );

        Notification::make()->title('New recovery codes generated')->success()->send();
    }

    public function disableTwoFactorAuthentication(): void
    {
        $this->validate(['password' => ['required', 'string']]);

        $user = auth()->user();

        if ($user === null || ! $this->passwordMatches($user->password)) {
            return;
        }

        $user->disableTwoFactor();

        $this->password = '';
        $this->resetEnrollmentState();

        app(AuditLogger::class)->record(
            'user.two_factor_disabled',
            "Two-factor authentication disabled for {$user->email}",
            $user,
        );

        Notification::make()->title('Two-factor authentication disabled')->success()->send();
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    private function passwordMatches(?string $hash): bool
    {
        if ($this->password === '' || $hash === null || ! Hash::check($this->password, $hash)) {
            $this->addError('password', 'Incorrect password.');

            return false;
        }

        return true;
    }

    private function resetEnrollmentState(): void
    {
        $this->enrolling = false;
        $this->secret = null;
        $this->qrSvg = null;
        $this->code = '';
    }
}
