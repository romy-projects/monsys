<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Validation\ValidationException;

/**
 * Phase 16A / T16-03 — panel login with an optional TOTP second factor.
 *
 * The password is verified first; the session is only started once the user
 * passes the second factor (or is not enrolled at all). Challenge state holds
 * the user id only — never the secret.
 */
class Login extends BaseLogin
{
    /** The user awaiting a second-factor code; null while on the password step. */
    public ?int $challengeUserId = null;

    public int $challengeAttempts = 0;

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        if ($this->challengeUserId !== null) {
            return $this->authenticateChallenge($data);
        }

        $credentials = $this->getCredentialsFromFormData($data);
        $provider = Filament::auth()->getProvider();
        $user = $provider->retrieveByCredentials($credentials);

        if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials)) {
            $this->throwFailureValidationException();
        }

        if (! $user->canAccessPanel(Filament::getCurrentPanel())) {
            $this->throwFailureValidationException();
        }

        if (! $user->hasTwoFactorEnabled()) {
            return $this->login($user, (bool) ($data['remember'] ?? false));
        }

        $this->challengeUserId = (int) $user->getKey();
        $this->challengeAttempts = 0;
        $this->form->fill(['email' => $user->email]);

        Notification::make()
            ->title('Two-factor authentication required')
            ->body('Enter the 6-digit code from your authenticator app, or one of your recovery codes.')
            ->info()
            ->send();

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function authenticateChallenge(array $data): ?LoginResponse
    {
        $user = User::query()->find($this->challengeUserId);
        $code = trim((string) ($data['code'] ?? ''));

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            $this->resetChallenge();
            $this->throwFailureValidationException();
        }

        if (! $user->verifyTwoFactorCode($code) && ! $user->consumeRecoveryCode($code)) {
            $this->challengeAttempts++;

            if ($this->challengeAttempts >= (int) config('security.two_factor.max_attempts', 5)) {
                $this->resetChallenge();
                $this->form->fill();
                $this->throwFailureValidationException();
            }

            throw ValidationException::withMessages([
                'data.code' => 'That code is invalid. Please try again.',
            ]);
        }

        return $this->login($user, (bool) ($data['remember'] ?? false));
    }

    protected function login(User $user, bool $remember): LoginResponse
    {
        Filament::auth()->login($user, $remember);
        session()->regenerate();
        $this->resetChallenge();

        return app(LoginResponse::class);
    }

    protected function resetChallenge(): void
    {
        $this->challengeUserId = null;
        $this->challengeAttempts = 0;
    }

    /**
     * @return array<int | string, string | Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getTwoFactorFormComponent(),
                        $this->getRememberFormComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    protected function getTwoFactorFormComponent(): Component
    {
        return TextInput::make('code')
            ->label('Authentication code')
            ->helperText('6-digit code from your authenticator app, or one of your recovery codes.')
            ->visible(fn (): bool => $this->challengeUserId !== null)
            ->autocomplete('one-time-code')
            ->extraInputAttributes(['tabindex' => 3, 'inputmode' => 'numeric']);
    }

    /**
     * The credentials fields are hidden once the second factor is required, so
     * the password never has to stay in the (browser-visible) Livewire state.
     */
    protected function getEmailFormComponent(): Component
    {
        return parent::getEmailFormComponent()
            ->visible(fn (): bool => $this->challengeUserId === null);
    }

    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()
            ->visible(fn (): bool => $this->challengeUserId === null);
    }
}
