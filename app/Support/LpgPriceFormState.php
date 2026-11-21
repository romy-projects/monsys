<?php

namespace App\Support;

use App\Filament\Resources\LpgPriceResource;
use App\Models\LpgPrice;
use Carbon\Carbon;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;

class LpgPriceFormState
{
    public static function sync(
        Get $get,
        Set $set,
        string $sellingPriceField,
        bool $includePurchasePrice = false,
        bool $useInvoiceDestination = false,
    ): void {
        $type = $get('cylinder_type');
        $date = $get('sale_date') ?? $get('issue_date');

        if (blank($type) || blank($date)) {
            $set('lpg_price_id', null);
            $set($sellingPriceField, null);

            if ($includePurchasePrice) {
                $set('purchase_price_at_sale', null);
            }

            return;
        }

        $date = Carbon::parse($date)->toDateString();
        $branchId = $useInvoiceDestination && $get('reference_type') === 'branch' && filled($get('reference_id'))
            ? (int) $get('reference_id')
            : (filled($get('branch_id')) ? (int) $get('branch_id') : null);
        $price = LpgPrice::priceAtDate($type, $date, $branchId);

        $set('lpg_price_id', $price?->id);
        $set($sellingPriceField, $price?->selling_price);

        if ($includePurchasePrice) {
            $set('purchase_price_at_sale', $price?->purchase_price);
        }

        if (! $price) {
            Notification::make()
                ->title('LPG Price belum tersedia')
                ->body("Tambahkan harga untuk tipe {$type} yang berlaku pada {$date} sebelum menyimpan transaksi.")
                ->warning()
                ->actions([
                    Action::make('create_lpg_price')
                        ->label('Input LPG Price')
                        ->url(LpgPriceResource::getUrl('create', [
                            'branch_id' => $branchId,
                            'cylinder_type' => $type,
                            'effective_date' => $date,
                        ]))
                        ->openUrlInNewTab(),
                ])
                ->send();
        }
    }
}
