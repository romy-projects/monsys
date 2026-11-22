<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\PurchaseOrderResource;
use App\Models\DeliveryOrder;
use Filament\Actions;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->authorize(fn () => PurchaseOrderResource::canDelete($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var DeliveryOrder $record */
        $record = $this->record;

        $data['document_type'] = 'po';
        $data['counterparty_type'] = 'branch';
        $data['counterparty_name'] = $record->counterparty_name;
        $data['destination_branch_id'] = $record->destination_branch_id;
        $data['origin_branch_id'] = $record->origin_branch_id;
        $data['requested_by'] = $record->requested_by;

        return $data;
    }
}
