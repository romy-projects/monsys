<?php

namespace App\Services;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 17 / T17-05 — session-only impersonation ("Login As").
 *
 * The real account is kept in the session under `impersonator_id`, so the
 * database, API tokens and remember-me cookies are never touched. Stopping the
 * session restores the real account immediately, without a password prompt.
 */
class ImpersonationService
{
    public const SESSION_KEY = 'impersonator_id';

    public const REASON_KEY = 'impersonation.reason';

    public const STARTED_AT_KEY = 'impersonation.started_at';

    public function __construct(private readonly AuditLogger $audit) {}

    public function isActive(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    public function initiator(): ?User
    {
        $id = session(self::SESSION_KEY);

        return $id === null ? null : User::query()->find($id);
    }

    public function reason(): ?string
    {
        $reason = session(self::REASON_KEY);

        return $reason === null ? null : (string) $reason;
    }

    public function startedAt(): ?string
    {
        $startedAt = session(self::STARTED_AT_KEY);

        return $startedAt === null ? null : (string) $startedAt;
    }

    public function start(User $target, ?string $reason = null): User
    {
        $actor = Auth::user();

        abort_unless($actor instanceof User, 403, 'You must be signed in to impersonate a user.');
        abort_unless($actor->hasPermission('impersonation.start'), 403, 'You are not allowed to impersonate users.');
        abort_if($this->isActive(), 403, 'An impersonation session is already active. Stop it before starting another.');
        abort_unless($target->canBeImpersonatedBy($actor), 403, 'This account cannot be impersonated.');
        abort_unless(
            $target->canAccessPanel(Filament::getPanel('admin')),
            403,
            'This account cannot access the admin panel.'
        );

        $this->audit->record(
            event: 'impersonation.started',
            description: "{$actor->name} started impersonating {$target->name}",
            subject: $target,
            properties: ['reason' => $reason],
            user: $actor,
            impersonator: $actor,
        );

        session()->put([
            self::SESSION_KEY => $actor->getKey(),
            self::REASON_KEY => $reason,
            self::STARTED_AT_KEY => now()->toIso8601String(),
        ]);

        Auth::login($target);
        session()->regenerate();

        return $target;
    }

    public function stop(): User
    {
        $initiator = $this->initiator();
        $target = Auth::user();

        abort_unless($initiator instanceof User, 403, 'No impersonation session is active.');
        abort_unless($initiator->hasPermission('impersonation.start'), 403, 'You are not allowed to stop this session.');

        session()->forget([self::SESSION_KEY, self::REASON_KEY, self::STARTED_AT_KEY]);
        Auth::login($initiator);
        session()->regenerate();

        $this->audit->record(
            event: 'impersonation.stopped',
            description: $target instanceof User
                ? "{$initiator->name} stopped impersonating {$target->name}"
                : "{$initiator->name} stopped an impersonation session",
            subject: $target instanceof User ? $target : null,
            user: $target instanceof User ? $target : $initiator,
            impersonator: $initiator,
        );

        return $initiator;
    }
}
