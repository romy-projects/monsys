<?php

namespace App\Filament\Resources\ReceivableResource\Pages;

use App\Filament\Resources\ReceivableResource;
use Filament\Resources\Pages\CreateRecord;

class CreateReceivable extends CreateRecord
{
    protected static string $resource = ReceivableResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $data['branch_id'] = $user->branch_id;
        }

        $data['created_by'] = $user->id;
        $data['status']     = 'outstanding';

        return $data;
    }
}
