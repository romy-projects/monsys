<?php

namespace App\Filament\Resources\CylinderCirculationResource\Pages;

use App\Filament\Resources\CylinderCirculationResource;
use App\Filament\Resources\Pages\CreateRecord;

class CreateCylinderCirculation extends CreateRecord
{
    protected static string $resource = CylinderCirculationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        abort_unless(CylinderCirculationResource::canCreate(), 403);

        $user = auth()->user();
        abort_unless($user->canAccessBranch((int) ($data['branch_id'] ?? 0)), 403);

        $data['created_by'] = auth()->id();

        return $data;
    }
}
