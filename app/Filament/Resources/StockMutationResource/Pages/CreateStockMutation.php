<?php

namespace App\Filament\Resources\StockMutationResource\Pages;

use App\Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\StockMutationResource;

class CreateStockMutation extends CreateRecord
{
    protected static string $resource = StockMutationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        $branchIds = array_filter([
            $data['branch_id'] ?? null,
            $data['source_branch_id'] ?? null,
            $data['destination_branch_id'] ?? null,
        ]);
        abort_unless(
            count($branchIds) > 0 && collect($branchIds)->every(fn ($branchId) => $user->canAccessBranch((int) $branchId)),
            403
        );

        $data['created_by'] = auth()->id();

        return $data;
    }
}
