<?php

namespace App\Support;

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
            ->whereIn('role', ['owner_pusat', 'regional_leader'])
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
                    ->url(\App\Filament\Resources\LpgPriceResource::getUrl('create', [
                        'branch_id' => $branchId,
                        'cylinder_type' => $type,
                        'effective_date' => $date,
                    ])),
            ])
            ->sendToDatabase($recipients);
    }
}
