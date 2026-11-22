<?php

namespace App\Filament\Resources\OpeningBalanceResource\Pages;

use App\Filament\Resources\OpeningBalanceResource;
use App\Filament\Resources\Pages\CreateRecord;

class CreateOpeningBalance extends CreateRecord
{
    protected static string $resource = OpeningBalanceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
