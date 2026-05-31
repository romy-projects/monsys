<?php

namespace App\Observers;

use App\Models\StockItem;
use App\Models\User;
use Filament\Notifications\Notification;

class StockItemObserver
{
    public function created(StockItem $stockItem): void
    {
        if ($stockItem->qty_full < 20) {
            $this->sendLowStockAlert($stockItem);
        }
    }

    public function updated(StockItem $stockItem): void
    {
        $wasOk  = $stockItem->getOriginal('qty_full') >= 20;
        $isLow  = $stockItem->qty_full < 20;

        // Only fire when crossing the threshold, not on every update while already low
        if ($wasOk && $isLow) {
            $this->sendLowStockAlert($stockItem);
        }
    }

    private function sendLowStockAlert(StockItem $stockItem): void
    {
        $branchName = $stockItem->branch?->name ?? "Branch #{$stockItem->branch_id}";

        $recipients = User::where(function ($q) use ($stockItem) {
            $q->whereIn('role', ['owner_pusat', 'regional_leader'])
              ->orWhere(function ($q2) use ($stockItem) {
                  $q2->where('branch_id', $stockItem->branch_id)
                     ->where('role', 'owner_cabang');
              });
        })->where('status', 'active')->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('⚠️ Low Stock Alert')
            ->body(
                "{$branchName}: {$stockItem->cylinder_type} full stock is " .
                number_format($stockItem->qty_full) . " pcs (threshold: 20 pcs)"
            )
            ->danger()
            ->sendToDatabase($recipients);
    }
}
