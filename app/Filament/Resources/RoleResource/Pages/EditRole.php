<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Services\AuditLogger;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (): bool => RoleResource::canDelete($this->getRecord())),
        ];
    }

    protected function afterSave(): void
    {
        app(AuditLogger::class)->record(
            event: 'role.updated',
            description: "Access role {$this->record->label} updated",
            subject: $this->record,
            properties: [
                'changes' => array_keys($this->record->getChanges()),
                'permissions' => $this->record->permissions()->pluck('key')->all(),
            ],
        );
    }
}
