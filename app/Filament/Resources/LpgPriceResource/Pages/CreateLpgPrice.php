<?php

namespace App\Filament\Resources\LpgPriceResource\Pages;

use App\Filament\Resources\LpgPriceResource;
use App\Filament\Resources\Pages\CreateRecord;

class CreateLpgPrice extends CreateRecord
{
    protected static string $resource = LpgPriceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        if (blank($data['branch_id'] ?? null)) {
            abort_unless($user->canAccessAllBranches(), 403);
        } else {
            abort_unless($user->canAccessBranch((int) $data['branch_id']), 403);
        }

        $data['created_by'] = auth()->id();

        return $data;
    }
}
