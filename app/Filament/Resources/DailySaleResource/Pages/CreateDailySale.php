<?php

namespace App\Filament\Resources\DailySaleResource\Pages;

use App\Filament\Resources\DailySaleResource;
use App\Models\Customer;
use App\Models\LpgPrice;
use Filament\Resources\Pages\CreateRecord;

class CreateDailySale extends CreateRecord
{
    protected static string $resource = DailySaleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        abort_unless(auth()->user()->canAccessBranch((int) ($data['branch_id'] ?? 0)), 403);
        abort_unless(
            empty($data['customer_id']) ||
                Customer::query()
                    ->whereKey($data['customer_id'])
                    ->where('branch_id', $data['branch_id'])
                    ->exists(),
            403
        );

        $price = LpgPrice::requiredPriceAtDate(
            $data['cylinder_type'],
            $data['sale_date'],
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
        );

        $data['lpg_price_id'] = $price->id;
        $data['purchase_price_at_sale'] = $price->purchase_price;
        $data['selling_price'] = $price->selling_price;
        $data['created_by'] = auth()->id();

        return $data;
    }
}
