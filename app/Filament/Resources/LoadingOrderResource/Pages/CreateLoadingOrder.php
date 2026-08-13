<?php

namespace App\Filament\Resources\LoadingOrderResource\Pages;

use App\Filament\Resources\LoadingOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLoadingOrder extends CreateRecord
{
    protected static string $resource = LoadingOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['document_type']     = 'lo';
        $data['counterparty_type'] = 'pertamina';
        $data['requested_by']      = auth()->id();

        return $data;
    }
}
