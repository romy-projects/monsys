<?php

namespace App\Filament\Resources\ReceivableResource\Pages;

use App\Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\ReceivableResource;

class CreateReceivable extends CreateRecord
{
    protected static string $resource = ReceivableResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if (empty($data['branch_id'])) {
            $data['branch_id'] = $user->branch_id;
        }

        if (! $user->canAccessBranch((int) $data['branch_id'])) {
            abort(403, 'You cannot create receivables for this branch.');
        }

        $data['created_by'] = $user->id;
        $data['status'] = 'outstanding';

        return $data;
    }
}
