<?php

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Filament\Resources\InvoiceResource;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\DocumentNumberService;
use App\Support\InvoicePricing;
use App\Support\OutstandingReconciliations;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        abort_unless(auth()->user()->canAccessBranch((int) ($data['branch_id'] ?? 0)), 403);
        $referenceType = $data['reference_type'] ?? 'customer';
        $referenceId = (int) ($data['reference_id'] ?? 0);
        abort_unless(
            $referenceType === 'branch'
                ? auth()->user()->canAccessBranch($referenceId) && Branch::query()->whereKey($referenceId)->exists()
                : Customer::query()->whereKey($referenceId)->where('branch_id', $data['branch_id'])->exists(),
            403
        );
        $price = InvoicePricing::requiredPrice($data);

        $data['lpg_price_id'] = $price->id;
        $data['unit_price'] = $price->selling_price;
        $data['total_amount'] = (int) ($data['quantity'] ?? 0) * (float) ($data['unit_price'] ?? 0);
        $data['created_by'] = auth()->id();

        // Map polymorphic reference
        $type = $data['reference_type'] ?? 'customer';
        $data['reference_type'] = $type === 'branch' ? 'branch' : 'customer';
        $data['reference_id'] = $data['reference_id'] ?? null;

        // Keep customer_id for backward compatibility
        if ($type === 'customer') {
            $data['customer_id'] = $data['reference_id'];
        } else {
            $data['customer_id'] = null;
        }

        // D6: only a branch invoice settles a reconciliation credit/claim.
        $data['notes'] = OutstandingReconciliations::appendReference(
            $data['notes'] ?? null,
            $type === 'branch' ? (int) $data['reference_id'] : null,
            $data['cylinder_type'] ?? null,
        );

        return $data;
    }

    /**
     * Allocate INV<year>-NNN inside the insert transaction (T11-09).
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            if (blank($data['invoice_number'] ?? null)) {
                $data['invoice_number'] = app(DocumentNumberService::class)->next('inv');
            }

            return Invoice::create($data);
        });
    }
}
