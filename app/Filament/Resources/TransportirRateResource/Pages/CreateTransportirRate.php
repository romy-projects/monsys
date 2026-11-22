<?php

namespace App\Filament\Resources\TransportirRateResource\Pages;

use App\Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\TransportirRateResource;

class CreateTransportirRate extends CreateRecord
{
    protected static string $resource = TransportirRateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
