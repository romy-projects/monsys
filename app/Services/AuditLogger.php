<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Phase 17 / T17-06 — single entry point for writing the audit trail.
 *
 * Every row records the effective user (`user_id`) and, when the request runs
 * inside an impersonation session, the real initiator (`impersonator_id`).
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        string $event,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?User $user = null,
        ?User $impersonator = null,
    ): AuditLog {
        $effectiveUser = $user ?? auth()->user();
        $initiator = $impersonator ?? $this->sessionImpersonator();

        $request = app()->runningInConsole() ? null : request();
        $userAgent = $request?->userAgent();

        return AuditLog::create([
            'event' => $event,
            'description' => $description,
            'user_id' => $effectiveUser?->getKey(),
            'impersonator_id' => $initiator?->getKey() === $effectiveUser?->getKey()
                ? null
                : $initiator?->getKey(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties === [] ? null : $properties,
            'ip_address' => $request?->ip(),
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 255, ''),
        ]);
    }

    public function sessionImpersonator(): ?User
    {
        $id = session(ImpersonationService::SESSION_KEY);

        if ($id === null) {
            return null;
        }

        return User::query()->find($id);
    }
}
