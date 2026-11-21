<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\AuditLogger;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    private ?int $previousRoleId = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        $this->previousRoleId = $this->record->role_id === null ? null : (int) $this->record->role_id;
    }

    protected function afterSave(): void
    {
        // A password typed in the edit form is a credential write like any
        // other: record it, but never store the plaintext itself.
        if (filled($this->data['password'] ?? null)) {
            app(AuditLogger::class)->record(
                event: 'user.password_changed_by_admin',
                description: "Password set for {$this->record->email} from the user form",
                subject: $this->record,
                properties: ['legacy_role' => $this->record->role],
            );
        }

        $currentRoleId = $this->record->role_id === null ? null : (int) $this->record->role_id;

        if ($this->previousRoleId === $currentRoleId) {
            return;
        }

        app(AuditLogger::class)->record(
            event: 'user.access_role_changed',
            description: "{$this->record->name} access role changed to ".($this->record->accessRole?->label ?? 'none'),
            subject: $this->record,
            properties: [
                'from_role_id' => $this->previousRoleId,
                'to_role_id' => $currentRoleId,
                'legacy_role' => $this->record->role,
            ],
        );
    }
}
