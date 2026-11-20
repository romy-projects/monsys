<?php

namespace App\Filament\Resources\SalesOrderResource\Pages;

use App\Filament\Resources\SalesOrderResource;
use App\Services\DocumentNumberService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateSalesOrder extends CreateRecord
{
    protected static string $resource = SalesOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['document_type']     = 'so';
        $data['counterparty_type'] = 'pertamina';
        $data['requested_by']      = auth()->id();

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
