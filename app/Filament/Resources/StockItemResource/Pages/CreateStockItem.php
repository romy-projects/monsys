<?php

namespace App\Filament\Resources\StockItemResource\Pages;

use App\Filament\Resources\StockItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStockItem extends CreateRecord
{
    protected static string $resource = StockItemResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        abort_unless(StockItemResource::canCreate(), 403);

        $data['branch_id'] = auth()->user()->branch_id;

        return $data;
    }
}
