<?php

namespace App\Filament\Resources\VehicleResource\Pages;

use App\Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\VehicleResource;
use Filament\Actions;

class EditVehicle extends EditRecord
{
    protected static string $resource = VehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
