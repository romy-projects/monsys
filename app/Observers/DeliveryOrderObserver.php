<?php

namespace App\Observers;

use App\Models\DeliveryOrder;
use App\Models\User;
use Filament\Notifications\Notification;

class DeliveryOrderObserver
{
    public function updated(DeliveryOrder $do): void
    {
        if (! $do->wasChanged('status')) {
            return;
        }

        match ($do->status) {
            'pending_approval' => $this->notifyPendingApproval($do),
            'approved'         => $this->notifyApproved($do),
            'on_transportir'   => $this->notifyOnTransportir($do),
            'delivered'        => $this->notifyDelivered($do),
            default            => null,
        };
    }

    private function notifyPendingApproval(DeliveryOrder $do): void
    {
        $recipients = User::whereIn('role', ['owner_pusat', 'regional_leader'])
            ->where('status', 'active')
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('📋 DO Pending Approval')
            ->body("DO #{$do->do_number} requires approval. " . number_format($do->quantity_ordered) . " pcs of {$do->cylinder_type}.")
            ->warning()
            ->sendToDatabase($recipients);
    }

    private function notifyApproved(DeliveryOrder $do): void
    {
        // Notify the requesting branch's owner/staff
        $recipients = User::where('branch_id', $do->origin_branch_id)
            ->whereIn('role', ['owner_cabang', 'staff_gudang'])
            ->where('status', 'active')
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('✅ DO Approved')
            ->body("DO #{$do->do_number} has been approved and is ready for dispatch.")
            ->success()
            ->sendToDatabase($recipients);
    }

    private function notifyOnTransportir(DeliveryOrder $do): void
    {
        // Notify destination branch that shipment is with transportir
        $recipients = User::where('branch_id', $do->destination_branch_id)
            ->whereIn('role', ['owner_cabang', 'staff_gudang'])
            ->where('status', 'active')
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('🚛 DO On Transportir')
            ->body("DO #{$do->do_number} is now with the transportir. ETA: " . ($do->eta?->format('d M Y') ?? 'N/A') . ".")
            ->warning()
            ->sendToDatabase($recipients);
    }

    private function notifyDelivered(DeliveryOrder $do): void
    {
        // Notify destination branch
        $recipients = User::where('branch_id', $do->destination_branch_id)
            ->whereIn('role', ['owner_cabang', 'staff_gudang'])
            ->where('status', 'active')
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $received = $do->quantity_received ? number_format($do->quantity_received) . ' pcs received' : 'delivered';

        Notification::make()
            ->title('📦 Delivery Arrived')
            ->body("DO #{$do->do_number} is marked as delivered — {$received}.")
            ->success()
            ->sendToDatabase($recipients);
    }
}
