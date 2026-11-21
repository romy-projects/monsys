<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        app(AuditLogger::class)->record(
            event: 'user.access_role_changed',
            description: "{$this->record->name} access role set to ".($this->record->accessRole?->label ?? 'none'),
            subject: $this->record,
            properties: [
                'from_role_id' => null,
                'to_role_id' => $this->record->role_id,
                'legacy_role' => $this->record->role,
            ],
        );
    }
}
