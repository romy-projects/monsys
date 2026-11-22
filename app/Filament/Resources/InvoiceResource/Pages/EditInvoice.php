<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\Pages\EditRecord;
use App\Models\Branch;
use App\Models\Customer;
use App\Support\InvoicePricing;
use Filament\Actions;

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
        abort_unless(
            auth()->user()->canAccessBranch((int) ($data['branch_id'] ?? $this->getRecord()->branch_id)),
            403
        );
        $branchId = (int) ($data['branch_id'] ?? $this->getRecord()->branch_id);
        $referenceType = $data['reference_type'] ?? 'customer';
        $referenceId = (int) ($data['reference_id'] ?? 0);
        abort_unless(
            $referenceType === 'branch'
                ? auth()->user()->canAccessBranch($referenceId) && Branch::query()->whereKey($referenceId)->exists()
                : Customer::query()->whereKey($referenceId)->where('branch_id', $branchId)->exists(),
            403
        );
        $price = InvoicePricing::requiredPrice($data);

        $data['lpg_price_id'] = $price->id;
        $data['unit_price'] = $price->selling_price;

        // Phase 19B / T19B-11 (D5): the expedition fee is part of the total on
        // the create page and in the API — the edit page used to drop it.
        InvoicePricing::assertExpeditionFeeWithinCost($data);
        $data['total_amount'] = InvoicePricing::total($data);

        // Map polymorphic reference
        $type = $data['reference_type'] ?? 'customer';
        $data['reference_type'] = $type === 'branch' ? 'branch' : 'customer';
        $data['reference_id'] = $data['reference_id'] ?? null;

        // Only set customer_id for actual customer references
        if ($type === 'customer') {
            $data['customer_id'] = $data['reference_id'];
        } else {
            $data['customer_id'] = null;
        }

        return $data;
    }
}
