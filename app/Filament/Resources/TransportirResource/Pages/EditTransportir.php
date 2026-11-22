<?php

namespace App\Filament\Resources\TransportirResource\Pages;

use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\TransportirResource;
use Filament\Actions;

class EditTransportir extends EditRecord
{
    protected static string $resource = TransportirResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
