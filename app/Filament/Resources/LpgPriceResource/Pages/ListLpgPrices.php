<?php

namespace App\Filament\Resources\LpgPriceResource\Pages;

use App\Filament\Resources\LpgPriceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLpgPrices extends ListRecords
{
    protected static string $resource = LpgPriceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
