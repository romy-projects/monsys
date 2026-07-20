<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Models\Invoice;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['total_amount'] = (int) ($data['quantity'] ?? 0) * (float) ($data['unit_price'] ?? 0);
        $data['created_by']   = auth()->id();

        // Map polymorphic reference
        $type = $data['reference_type'] ?? 'customer';
        $data['reference_type'] = $type === 'branch' ? 'branch' : 'customer';
        $data['reference_id']   = $data['reference_id'] ?? null;

        // Keep customer_id for backward compatibility
        if ($type === 'customer') {
            $data['customer_id'] = $data['reference_id'];
        }

        return $data;
    }
}
