<?php

namespace App\Filament\Resources\CylinderCirculationResource\Pages;

use App\Filament\Resources\CylinderCirculationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCylinderCirculation extends CreateRecord
{
    protected static string $resource = CylinderCirculationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        return $data;
    }
}
