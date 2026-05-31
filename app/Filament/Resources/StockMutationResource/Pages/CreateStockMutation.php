<?php

namespace App\Filament\Resources\StockMutationResource\Pages;

use App\Filament\Resources\StockMutationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStockMutation extends CreateRecord
{
    protected static string $resource = StockMutationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
