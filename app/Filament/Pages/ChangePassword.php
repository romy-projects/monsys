<?php

namespace App\Filament\Pages;

use App\Services\UserPasswordService;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;

/**
 * Phase 18 / T18-01 — self-service password rotation.
 *
 * Reachable from the user menu for everybody, and the only page the
 * ForcePasswordChange middleware leaves open while the account still carries
 * the "must change password" flag.
 */
class ChangePassword extends Page
{
    protected static string $view = 'filament.pages.change-password';

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $title = 'Change Password';

    protected static ?string $slug = 'change-password';

    protected static bool $shouldRegisterNavigation = false;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function submit(): void
    {
        $service = app(UserPasswordService::class);

        $this->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'min:'.$service->minimumLength(),
                'same:password_confirmation',
                'different:current_password',
            ],
        ], attributes: [
            'current_password' => 'current password',
            'password' => 'new password',
            'password_confirmation' => 'new password confirmation',
        ]);

        $user = auth()->user();

        if ($user === null) {
            return;
        }

        if (! $service->change($user, $this->current_password, $this->password)) {
            $this->addError('current_password', 'The current password is incorrect.');

            return;
        }

        $this->reset('current_password', 'password', 'password_confirmation');

        // AuthenticateSession refreshes this session's stored hash at the end of
        // the request, so the browser stays signed in while every other session
        // and all API tokens issued before the change are invalidated.
        Notification::make()
            ->title('Password changed')
            ->body('Other devices have been signed out. Use the new password next time.')
            ->success()
            ->send();

        $this->redirect(Dashboard::getUrl(panel: 'admin'), navigate: true);
    }

    public function isForced(): bool
    {
        return (bool) auth()->user()?->mustChangePassword();
    }
}
