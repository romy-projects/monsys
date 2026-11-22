<?php

namespace App\Filament\Resources\ExpeditionLoadingOrderResource\Pages;

use App\Filament\Resources\ExpeditionLoadingOrderResource;
use App\Filament\Resources\Pages\EditRecord;
use Filament\Actions;

class EditExpeditionLoadingOrder extends EditRecord
{
    protected static string $resource = ExpeditionLoadingOrderResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Editing an Expedition LO must never turn it into a Transportir LO,
        // nor move the fee onto the transportir.
        $data['document_type'] = 'lo';
        $data['lo_kind'] = 'expedition';
        $data['cost_borne_by'] = 'sum';
        abort_unless(
            auth()->user()->canAccessBranch((int) ($data['destination_branch_id'] ?? $this->getRecord()->destination_branch_id)),
            403
        );

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn () => ExpeditionLoadingOrderResource::canDelete($this->getRecord())),
        ];
    }
}
