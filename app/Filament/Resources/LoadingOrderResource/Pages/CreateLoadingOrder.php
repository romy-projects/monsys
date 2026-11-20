<?php

namespace App\Filament\Resources\LoadingOrderResource\Pages;

use App\Filament\Resources\LoadingOrderResource;
use App\Services\DocumentNumberService;
use App\Support\OutstandingReconciliations;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateLoadingOrder extends CreateRecord
{
    protected static string $resource = LoadingOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['document_type']     = 'lo';
        $data['lo_kind']           = 'transportir';
        $data['counterparty_type'] = 'pertamina';
        $data['requested_by']      = auth()->id();

        // A Transportir LO never carries an expedition fee — the transportir
        // arranges (and pays for) its own expedition (BALI-13).
        $data['cost_borne_by']   = 'transporter';
        $data['expedition_cost'] = null;

        // D6: the credit this LO settles must be referenced in the notes.
        $data['notes'] = OutstandingReconciliations::appendReference(
            $data['notes'] ?? null,
            $data['destination_branch_id'] ?? null,
            $data['cylinder_type'] ?? null,
        );

        return $data;
    }

    /**
     * Allocate LOT<year>-NNN inside the insert transaction. `next()` locks the
     * counter row, so concurrent creates queue instead of colliding on the
     * unique index of delivery_orders.do_number (T11-09).
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            if (blank($data['do_number'] ?? null)) {
                $data['do_number'] = app(DocumentNumberService::class)->next('lo_t');
            }

            return static::getModel()::create($data);
        });
    }
}

