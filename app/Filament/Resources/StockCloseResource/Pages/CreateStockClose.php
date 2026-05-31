<?php

namespace App\Filament\Resources\StockCloseResource\Pages;

use App\Filament\Resources\StockCloseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStockClose extends CreateRecord
{
    protected static string $resource = StockCloseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $data['branch_id'] = $user->branch_id;
        }

        return $data;
    }
}
