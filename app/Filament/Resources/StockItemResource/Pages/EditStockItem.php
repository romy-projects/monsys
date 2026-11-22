<?php

namespace App\Filament\Resources\StockItemResource\Pages;

use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\StockItemResource;
use Filament\Actions;

class EditStockItem extends EditRecord
{
    protected static string $resource = StockItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        abort_unless(StockItemResource::canEdit($this->record), 403);

        $data['branch_id'] = $this->record->branch_id;

        return $data;
    }
}
