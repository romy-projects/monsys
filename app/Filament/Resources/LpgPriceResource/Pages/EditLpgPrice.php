<?php

namespace App\Filament\Resources\LpgPriceResource\Pages;

use App\Filament\Resources\LpgPriceResource;
use App\Filament\Resources\Pages\EditRecord;

class EditLpgPrice extends EditRecord
{
    protected static string $resource = LpgPriceResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();
        $branchId = $data['branch_id'] ?? $this->getRecord()->branch_id;
        if (blank($branchId)) {
            abort_unless($user->canAccessAllBranches(), 403);
        } else {
            abort_unless($user->canAccessBranch((int) $branchId), 403);
        }

        return $data;
    }
}
