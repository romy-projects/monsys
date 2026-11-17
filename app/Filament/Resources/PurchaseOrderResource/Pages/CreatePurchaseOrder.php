<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Filament\Resources\PurchaseOrderResource;
use App\Models\Branch;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        $mainBranch = Branch::mainBranch()->first();

        abort_unless($mainBranch, 422, 'The HQ branch is not configured.');

        if ($user->isOwnerPusat()) {
            abort_unless(
                Branch::active()
                    ->whereKey($data['destination_branch_id'] ?? null)
                    ->where('id', '!=', $mainBranch->id)
                    ->exists(),
                422,
                'Select an active destination branch.'
            );
        } else {
            abort_unless($user->canCreatePurchaseOrder(), 403);
            $data['destination_branch_id'] = $user->branch_id;
        }

        $data['document_type']     = 'po';
        $data['counterparty_type'] = 'branch';
        $data['counterparty_name'] = $mainBranch->name;
        $data['origin_branch_id']  = $mainBranch->id;
        $data['requested_by']      = $user->id;

        return $data;
    }
}
