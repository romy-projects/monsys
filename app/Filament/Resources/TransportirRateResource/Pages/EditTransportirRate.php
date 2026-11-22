<?php

namespace App\Filament\Resources\TransportirRateResource\Pages;

use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\TransportirRateResource;
use Filament\Actions;

class EditTransportirRate extends EditRecord
{
    protected static string $resource = TransportirRateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
