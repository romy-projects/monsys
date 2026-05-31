<?php

namespace App\Filament\Resources\LpgPriceResource\Pages;

use App\Filament\Resources\LpgPriceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLpgPrice extends CreateRecord
{
    protected static string $resource = LpgPriceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
