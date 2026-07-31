<?php

namespace App\Filament\Resources\CylinderCirculationResource\Pages;

use App\Filament\Resources\CylinderCirculationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCylinderCirculation extends EditRecord
{
    protected static string $resource = CylinderCirculationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
