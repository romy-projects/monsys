<?php

namespace App\Filament\Resources\StockMutationResource\Pages;

use App\Filament\Resources\StockMutationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStockMutations extends ListRecords
{
    protected static string $resource = StockMutationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
