<?php

namespace App\Filament\Resources\SalesTargetResource\Pages;

use App\Filament\Resources\SalesTargetResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSalesTarget extends EditRecord
{
    protected static string $resource = SalesTargetResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_unless(auth()->user()->canAccessBranch((int) ($data['branch_id'] ?? $this->record->branch_id)), 403);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
