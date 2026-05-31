<?php

namespace App\Filament\Resources\StockCloseResource\Pages;

use App\Filament\Resources\StockCloseResource;
use App\Models\StockClose;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStockCloses extends ListRecords
{
    protected static string $resource = StockCloseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }
}
