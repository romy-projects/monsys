<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\RoleResource;
use App\Services\AuditLogger;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function afterCreate(): void
    {
        app(AuditLogger::class)->record(
            event: 'role.created',
            description: "Access role {$this->record->label} created",
            subject: $this->record,
            properties: [
                'permissions' => $this->record->permissions()->pluck('key')->all(),
            ],
        );
    }
}
