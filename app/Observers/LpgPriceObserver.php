<?php

namespace App\Observers;

use App\Models\LpgPrice;
use App\Models\DeliveryOrder;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

class LpgPriceObserver
{
    public function saving(LpgPrice $price): void
    {
        if (! $price->purchase_order_id) {
            return;
        }

        $purchaseOrder = DeliveryOrder::purchaseOrders()->find($price->purchase_order_id);

        if (! $purchaseOrder) {
            throw ValidationException::withMessages([
                'purchase_order_id' => 'Select a valid purchase order.',
            ]);
        }

        if ((int) $purchaseOrder->destination_branch_id !== (int) $price->branch_id
            || $purchaseOrder->cylinder_type !== $price->cylinder_type) {
            throw ValidationException::withMessages([
                'purchase_order_id' => 'The purchase order must match the LPG Price branch and cylinder type.',
            ]);
        }

        $price->effective_date = $purchaseOrder->created_at
            ->copy()
            ->addDays(14)
            ->toDateString();
    }

    public function created(LpgPrice $price): void
    {
        $this->notifyAffectedUsers($price);
    }

    public function updated(LpgPrice $price): void
    {
        if ($price->wasChanged(['branch_id', 'cylinder_type', 'purchase_price', 'selling_price', 'effective_date'])) {
            $this->notifyAffectedUsers($price);
        }
    }

    private function notifyAffectedUsers(LpgPrice $price): void
    {
        $admins = User::query()
            ->whereIn('role', ['owner_pusat', 'regional_leader'])
            ->where('status', 'active');

        $branchUsers = User::query()
            ->whereIn('role', ['owner_cabang', 'staff_gudang'])
            ->where('status', 'active');

        if ($price->branch_id) {
            $branchIds = [$price->branch_id];
        } else {
            $branchIds = User::query()
                ->whereIn('role', ['owner_cabang', 'staff_gudang'])
                ->where('status', 'active')
                ->whereNotNull('branch_id')
                ->distinct()
                ->pluck('branch_id')
                ->filter(fn(int $branchId): bool => LpgPrice::priceAtDate(
                    $price->cylinder_type,
                    $price->effective_date->toDateString(),
                    $branchId,
                )?->is($price) ?? false)
                ->all();
        }

        $branchUsers->whereIn('branch_id', $branchIds);
        $recipients = $admins->get()->merge($branchUsers->get())->unique('id')->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $scope = $price->branch_id
            ? 'for ' . ($price->branch?->name ?? 'the selected branch')
            : 'for the affected branches';

        Notification::make()
            ->title('LPG Price changed')
            ->body("LPG Price #{$price->id} for {$price->cylinder_type} {$scope} is effective {$price->effective_date?->format('d M Y')}. Selling price: Rp " . number_format((float) $price->selling_price, 0, ',', '.') . '.')
            ->info()
            ->sendToDatabase($recipients);
    }
}
