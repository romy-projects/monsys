<?php

namespace App\Filament\Resources\StockCloseResource\Pages;

use App\Filament\Resources\StockCloseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStockClose extends EditRecord
{
    protected static string $resource = StockCloseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
