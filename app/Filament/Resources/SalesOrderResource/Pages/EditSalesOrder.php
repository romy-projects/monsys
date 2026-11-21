<?php

namespace App\Filament\Resources\SalesOrderResource\Pages;

use App\Filament\Resources\SalesOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSalesOrder extends EditRecord
{
    protected static string $resource = SalesOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn () => SalesOrderResource::canDelete($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();
        $record = $this->getRecord();
        $destinationBranchId = $data['destination_branch_id'] ?? $record->destination_branch_id;
        if ($destinationBranchId !== null) {
            abort_unless($user->canAccessBranch((int) $destinationBranchId), 403);
        }
        $data['origin_branch_id'] = $record->origin_branch_id;
        $data['requested_by'] = $record->requested_by;

        return $data;
    }
}
