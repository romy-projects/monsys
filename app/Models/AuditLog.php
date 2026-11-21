<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only audit trail row. Created through `App\Services\AuditLogger`.
 *
 * `user_id` is the effective user of the request (the impersonated account when
 * impersonating) and `impersonator_id` is the real person behind the session.
 */
class AuditLog extends Model
{
    protected $fillable = [
        'event',
        'description',
        'user_id',
        'impersonator_id',
        'subject_type',
        'subject_id',
        'properties',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // The trail is append-only: no updates, no deletes from the application.
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => false);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function getActorNameAttribute(): string
    {
        if ($this->impersonator_id === null) {
            return $this->user?->name ?? 'System';
        }

        return ($this->impersonator?->name ?? 'Unknown').' as '.($this->user?->name ?? 'Unknown');
    }
}
