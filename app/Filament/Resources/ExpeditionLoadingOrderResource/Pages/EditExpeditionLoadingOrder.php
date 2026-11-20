<?php

namespace App\Filament\Resources\ExpeditionLoadingOrderResource\Pages;

use App\Filament\Resources\ExpeditionLoadingOrderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditExpeditionLoadingOrder extends EditRecord
{
    protected static string $resource = ExpeditionLoadingOrderResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Editing an Expedition LO must never turn it into a Transportir LO,
        // nor move the fee onto the transportir.
        $data['document_type'] = 'lo';
        $data['lo_kind']       = 'expedition';
        $data['cost_borne_by'] = 'sum';

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
