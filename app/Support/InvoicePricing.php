<?php

namespace App\Support;

use App\Filament\Resources\LpgPriceResource;
use App\Models\Branch;
use App\Models\LpgPrice;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class InvoicePricing
{
    /**
     * Branch invoices are priced for their branch destination; other invoices
     * use the branch that owns the invoice.
     */
    public static function branchId(array $data): ?int
    {
        if (($data['reference_type'] ?? null) === 'branch' && filled($data['reference_id'] ?? null)) {
            return (int) $data['reference_id'];
        }

        return filled($data['branch_id'] ?? null) ? (int) $data['branch_id'] : null;
    }

    /**
     * Phase 19A / T19A-05: the single definition of an invoice total
     * (product lines + any delivery fee passed on to the buyer).
     *
     * The Filament create page and `Api\InvoiceController` both use this, so the
     * two entry points can no longer disagree about whether the expedition fee
     * is part of the bill.
     *
     * @param  array<string, mixed>  $data
     */
    public static function total(array $data): float
    {
        $product = (float) ($data['unit_price'] ?? 0) * (float) ($data['quantity'] ?? 0);

        return round($product + (float) ($data['expedition_fee_amount'] ?? 0), 2);
    }

    /**
     * Phase 19A / T19A-06: a shipment may decide per transaction whether the
     * delivery fee is billed to the buyer (`delivery_orders.expedition_fee_charged_to_buyer`);
     * when the column is null the global config wins.
     */
    public static function expeditionChargedToBuyer(mixed $override): bool
    {
        return $override === null
            ? (bool) config('app.expedition_fee_charged_to_buyer', false)
            : (bool) $override;
    }

    /**
     * Phase 19B / T19B-11: a delivery fee passed on to the buyer can never
     * exceed the real expedition cost of the shipment, and never be negative.
     * Without this guard a mistyped fee would inflate both AR and the recovery
     * line 5-132 (see docs/lapkeu-gap-analysis.md §6 D5).
     *
     * @param  array<string, mixed>  $data
     */
    public static function assertExpeditionFeeWithinCost(array $data): void
    {
        $fee = round((float) ($data['expedition_fee_amount'] ?? 0), 2);

        if ($fee < 0) {
            throw ValidationException::withMessages([
                'expedition_fee_amount' => 'Ongkos kirim yang dibebankan ke pembeli tidak boleh negatif.',
            ]);
        }

        $cost = $data['expedition_cost_at_sale'] ?? null;

        if ($fee > 0 && $cost !== null && round((float) $cost, 2) < $fee) {
            throw ValidationException::withMessages([
                'expedition_fee_amount' => 'Ongkos kirim yang dibebankan ke pembeli tidak boleh melebihi biaya expedisi sebenarnya.',
            ]);
        }
    }

    public static function requiredPrice(array $data): LpgPrice
    {
        $type = (string) ($data['cylinder_type'] ?? '');
        $date = (string) ($data['issue_date'] ?? today()->toDateString());
        $branchId = self::branchId($data);
        $price = LpgPrice::priceAtDate($type, $date, $branchId);

        if ($price) {
            return $price;
        }

        self::notifyAdmins($type, $date, $branchId);

        throw ValidationException::withMessages([
            'lpg_price_id' => "No LPG Price is configured for cylinder type {$type} at the destination branch on {$date}. Configure an effective LPG Price before saving this invoice.",
        ]);
    }

    public static function notifyAdmins(string $type, string $date, ?int $branchId): void
    {
        $branchName = $branchId ? Branch::find($branchId)?->name : null;
        $destination = $branchName ? " for {$branchName}" : '';
        $recipients = User::query()
            ->whereHas('accessRole.permissions', fn ($query) => $query->whereIn(
                'permissions.key',
                ['system.business_admin', 'system.regional_access']
            ))
            ->where('status', 'active')
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('LPG Price required for invoice')
            ->body("No effective LPG Price is configured{$destination} for {$type} on {$date}. Add the price before this invoice can be saved.")
            ->warning()
            ->actions([
                Action::make('create_lpg_price')
                    ->label('Configure LPG Price')
                    ->url(LpgPriceResource::getUrl('create', [
                        'branch_id' => $branchId,
                        'cylinder_type' => $type,
                        'effective_date' => $date,
                    ])),
            ])
            ->sendToDatabase($recipients);
    }
}
