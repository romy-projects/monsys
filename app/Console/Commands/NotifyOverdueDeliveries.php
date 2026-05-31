<?php

namespace App\Console\Commands;

use App\Models\DeliveryOrder;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

class NotifyOverdueDeliveries extends Command
{
    protected $signature   = 'do:notify-overdue';
    protected $description = 'Send in-app notifications for delivery orders past their ETA';

    public function handle(): int
    {
        $overdue = DeliveryOrder::whereIn('status', ['approved', 'in_transit'])
            ->whereNotNull('eta')
            ->where('eta', '<', today())
            ->with(['originBranch', 'destinationBranch'])
            ->get();

        if ($overdue->isEmpty()) {
            $this->info('No overdue deliveries found.');

            return self::SUCCESS;
        }

        foreach ($overdue as $do) {
            $daysLate = today()->diffInDays($do->eta);

            $recipients = User::where(function ($q) use ($do) {
                $q->whereIn('role', ['owner_pusat', 'regional_leader'])
                  ->orWhere(function ($q2) use ($do) {
                      $q2->where('branch_id', $do->destination_branch_id)
                         ->whereIn('role', ['owner_cabang', 'staff_gudang']);
                  });
            })->where('status', 'active')->get();

            if ($recipients->isEmpty()) {
                continue;
            }

            $dest = $do->destinationBranch?->name ?? "Branch #{$do->destination_branch_id}";

            Notification::make()
                ->title('🚨 Delivery Overdue')
                ->body(
                    "DO #{$do->do_number} to {$dest} is {$daysLate} day(s) past ETA. " .
                    "Status: " . ucfirst(str_replace('_', ' ', $do->status)) . "."
                )
                ->danger()
                ->sendToDatabase($recipients);
        }

        $this->info("Notified for {$overdue->count()} overdue deliveries.");

        return self::SUCCESS;
    }
}
