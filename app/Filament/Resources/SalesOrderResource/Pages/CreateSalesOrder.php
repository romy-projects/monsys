<?php

namespace App\Filament\Resources\SalesOrderResource\Pages;

use App\Filament\Resources\SalesOrderResource;
use App\Models\Branch;
use App\Services\DocumentNumberService;
use App\Support\OutstandingReconciliations;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateSalesOrder extends CreateRecord
{
    protected static string $resource = SalesOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        $mainBranch = Branch::mainBranch()->first();
        abort_unless(
            $user->hasPermission('sales_orders.create')
                && $mainBranch !== null
                && $user->canAccessBranch((int) $mainBranch->id),
            403
        );
        if (! empty($data['destination_branch_id'])) {
            abort_unless($user->canAccessBranch((int) $data['destination_branch_id']), 403);
        }

        $data['document_type'] = 'so';
        $data['counterparty_type'] = 'pertamina';
        $data['origin_branch_id'] = $mainBranch->id;
        $data['requested_by'] = auth()->id();

        // D6: the credit this shipment settles must be referenced in the notes.
        $data['notes'] = OutstandingReconciliations::appendReference(
            $data['notes'] ?? null,
            $data['destination_branch_id'] ?? null,
            $data['cylinder_type'] ?? null,
        );

        return $data;
    }

    /**
     * Allocate SO<year>-NNN inside the insert transaction (T11-09).
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            if (blank($data['do_number'] ?? null)) {
                $data['do_number'] = app(DocumentNumberService::class)->next('so');
            }

            return static::getModel()::create($data);
        });
    }
}
