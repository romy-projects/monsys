<?php

namespace App\Filament\Resources\SalesTargetResource\Pages;

use App\Filament\Resources\SalesTargetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSalesTarget extends CreateRecord
{
    protected static string $resource = SalesTargetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        abort_unless(auth()->user()->canAccessBranch((int) ($data['branch_id'] ?? 0)), 403);

        $data['created_by'] = auth()->id();

        return $data;
    }
}
