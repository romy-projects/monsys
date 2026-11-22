<?php

namespace App\Filament\Resources\ExpeditionLoadingOrderResource\Pages;

use App\Filament\Resources\ExpeditionLoadingOrderResource;
use App\Filament\Resources\Pages\CreateRecord;
use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Services\DocumentNumberService;
use App\Support\OutstandingReconciliations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateExpeditionLoadingOrder extends CreateRecord
{
    protected static string $resource = ExpeditionLoadingOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        $mainBranch = Branch::mainBranch()->first();
        abort_unless(
            $user->hasPermission('loading_orders.create')
                && $mainBranch !== null
                && $user->canAccessBranch((int) $mainBranch->id)
                && $user->canAccessBranch((int) ($data['destination_branch_id'] ?? 0)),
            403
        );
        abort_unless(
            DeliveryOrder::transportirLoadingOrders()
                ->accessibleTo($user)
                ->where('do_number', $data['transportir_lo_number'] ?? null)
                ->exists(),
            403
        );

        $data['origin_branch_id'] = $mainBranch->id;
        $data['document_type'] = 'lo';
        $data['lo_kind'] = 'expedition';
        $data['counterparty_type'] = 'branch';
        $data['cost_borne_by'] = 'sum';
        $data['requested_by'] = auth()->id();
        $data['counterparty_name'] ??= Branch::find($data['destination_branch_id'] ?? null)?->name;

        // D6: the credit this LO settles must be referenced in the notes.
        $data['notes'] = OutstandingReconciliations::appendReference(
            $data['notes'] ?? null,
            $data['destination_branch_id'] ?? null,
            $data['cylinder_type'] ?? null,
        );

        return $data;
    }

    /** Allocate LOE<year>-NNN atomically (separate series from LOT). */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            if (blank($data['do_number'] ?? null)) {
                $data['do_number'] = app(DocumentNumberService::class)->next('lo_e');
            }

            return static::getModel()::create($data);
        });
    }
}
