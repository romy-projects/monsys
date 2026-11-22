<?php

namespace App\Filament\Resources\LoadingOrderResource\Pages;

use App\Filament\Resources\LoadingOrderResource;
use App\Filament\Resources\Pages\EditRecord;
use Filament\Actions;

class EditLoadingOrder extends EditRecord
{
    protected static string $resource = LoadingOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn () => LoadingOrderResource::canDelete($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();
        $record = $this->getRecord();
        $destinationBranchId = $data['destination_branch_id'] ?? $record->destination_branch_id;
        abort_unless($user->canAccessBranch((int) $destinationBranchId), 403);

        $data['origin_branch_id'] = $record->origin_branch_id;
        $data['requested_by'] = $record->requested_by;

        return $data;
    }
}
