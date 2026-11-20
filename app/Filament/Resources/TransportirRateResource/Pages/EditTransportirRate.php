<?php

namespace App\Filament\Resources\TransportirRateResource\Pages;

use App\Filament\Resources\TransportirRateResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

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
