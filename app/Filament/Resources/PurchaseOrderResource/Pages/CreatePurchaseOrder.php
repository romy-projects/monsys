<?php

namespace App\Filament\Resources\PurchaseOrderResource\Pages;

use App\Filament\Resources\PurchaseOrderResource;
use App\Models\Branch;
use App\Services\DocumentNumberService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        $mainBranch = Branch::mainBranch()->first();

        abort_unless($mainBranch, 422, 'The HQ branch is not configured.');

        abort_unless($user->hasPermission('purchase_orders.create'), 403);
        $destinationBranchId = (int) ($data['destination_branch_id'] ?? $user->branch_id ?? 0);
        abort_unless(
            Branch::active()
                ->whereKey($destinationBranchId)
                ->where('id', '!=', $mainBranch->id)
                ->exists() && $user->canAccessBranch($destinationBranchId),
            403
        );
        $data['destination_branch_id'] = $destinationBranchId;

        $data['document_type'] = 'po';
        $data['counterparty_type'] = 'branch';
        $data['counterparty_name'] = $mainBranch->name;
        $data['origin_branch_id'] = $mainBranch->id;
        $data['requested_by'] = $user->id;

        return $data;
    }

    /**
     * Allocate PO<year>-NNN inside the insert transaction. `next()` locks the
     * counter row, so concurrent creates queue instead of colliding on the
     * unique index of delivery_orders.do_number (T11-09).
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            if (blank($data['do_number'] ?? null)) {
                $data['do_number'] = app(DocumentNumberService::class)->next('po');
            }

            return static::getModel()::create($data);
        });
    }
}
