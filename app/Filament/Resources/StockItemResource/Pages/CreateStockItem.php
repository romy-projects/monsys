<?php

namespace App\Filament\Resources\StockItemResource\Pages;

use App\Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\StockItemResource;

class CreateStockItem extends CreateRecord
{
    protected static string $resource = StockItemResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        abort_unless(StockItemResource::canCreate(), 403);

        $user = auth()->user();
        $branchId = (int) ($data['branch_id'] ?? $user->branch_id ?? 0);
        abort_unless($user->canAccessBranch($branchId), 403);
        $data['branch_id'] = $branchId;

        return $data;
    }
}
