<?php

namespace App\Filament\Resources\StockCloseResource\Pages;

use App\Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\StockCloseResource;

class CreateStockClose extends CreateRecord
{
    protected static string $resource = StockCloseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        abort_unless($user->canAccessBranch((int) ($data['branch_id'] ?? 0)), 403);

        return $data;
    }
}
