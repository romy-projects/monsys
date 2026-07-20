<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['total_amount'] = (int) ($data['quantity'] ?? 0) * (float) ($data['unit_price'] ?? 0);

        // Map polymorphic reference
        $type = $data['reference_type'] ?? 'customer';
        $data['reference_type'] = $type === 'branch' ? 'branch' : 'customer';
        $data['reference_id']   = $data['reference_id'] ?? null;

        // Only set customer_id for actual customer references
        if ($type === 'customer') {
            $data['customer_id'] = $data['reference_id'];
        } else {
            $data['customer_id'] = null;
        }

        return $data;
    }
}
