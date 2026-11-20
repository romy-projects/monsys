<?php

namespace App\Filament\Resources\TransportirRateResource\Pages;

use App\Filament\Resources\TransportirRateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTransportirRates extends ListRecords
{
    protected static string $resource = TransportirRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
