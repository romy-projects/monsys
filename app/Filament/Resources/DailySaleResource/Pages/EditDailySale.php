<?php

namespace App\Filament\Resources\DailySaleResource\Pages;

use App\Filament\Resources\DailySaleResource;
use App\Filament\Resources\Pages\EditRecord;
use App\Models\Customer;
use App\Models\LpgPrice;
use Filament\Actions;

class EditDailySale extends EditRecord
{
    protected static string $resource = DailySaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();
        abort_unless(auth()->user()->canAccessBranch((int) ($data['branch_id'] ?? $record->branch_id)), 403);
        abort_unless(
            empty($data['customer_id']) ||
                Customer::query()
                    ->whereKey($data['customer_id'])
                    ->where('branch_id', $data['branch_id'] ?? $record->branch_id)
                    ->exists(),
            403
        );

        $priceInputsChanged = $record->branch_id !== (int) $data['branch_id']
            || $record->cylinder_type !== $data['cylinder_type']
            || $record->sale_date->toDateString() !== $data['sale_date'];

        if ($priceInputsChanged) {
            $price = LpgPrice::requiredPriceAtDate(
                $data['cylinder_type'],
                $data['sale_date'],
                (int) $data['branch_id'],
            );

            $data['lpg_price_id'] = $price->id;
            $data['purchase_price_at_sale'] = $price->purchase_price;
            $data['selling_price'] = $price->selling_price;
        } else {
            $data['lpg_price_id'] = $record->lpg_price_id;
            $data['purchase_price_at_sale'] = $record->purchase_price_at_sale;
            $data['selling_price'] = $record->selling_price;
        }

        return $data;
    }
}
