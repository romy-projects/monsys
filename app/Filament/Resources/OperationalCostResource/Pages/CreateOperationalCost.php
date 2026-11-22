<?php

namespace App\Filament\Resources\OperationalCostResource\Pages;

use App\Filament\Resources\OperationalCostResource;
use App\Filament\Resources\Pages\CreateRecord;

class CreateOperationalCost extends CreateRecord
{
    protected static string $resource = OperationalCostResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        abort_unless(auth()->user()->canAccessBranch((int) ($data['branch_id'] ?? 0)), 403);

        $data['created_by'] = auth()->id();

        return $data;
    }
}
