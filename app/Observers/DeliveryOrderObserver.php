<?php

namespace App\Observers;

use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\StockItem;
use App\Models\StockMutation;
use App\Models\User;
use Filament\Notifications\Notification;

class DeliveryOrderObserver
{
    public function updated(DeliveryOrder $do): void
    {
        // Sync: shipment_status = delivered_to_destination → auto-transition do_status to delivered
        if ($do->wasChanged('shipment_status') && $do->shipment_status === 'delivered_to_destination') {
            $do->status = 'delivered';
            $do->saveQuietly();
            $this->handleDelivered($do);
            return;
        }

        if (! $do->wasChanged('status')) {
            return;
        }

        match ($do->status) {
            'pending_approval' => $this->notifyPendingApproval($do),
            'approved'         => $this->handleApproved($do),
            'on_transportir'   => $this->notifyOnTransportir($do),
            'delivered'        => $this->handleDelivered($do),
            default            => null,
        };
    }

    private function handleApproved(DeliveryOrder $do): void
    {
        $this->notifyApproved($do);

        // T7-17: SO approved → auto-create DO
        if ($do->document_type === 'so') {
            $this->createDeliveryOrderFromSo($do);
        }

        // T7-18: PO approved → auto-create DO for fulfillment
        if ($do->document_type === 'po') {
            $this->createDeliveryOrderFromPo($do);
        }
    }

    /**
     * T7-17: When an SO is approved, auto-create a DO to Pertamina.
     */
    private function createDeliveryOrderFromSo(DeliveryOrder $so): void
    {
        $mainBranch = Branch::mainBranch()->first();

        $year  = date('Y');
        $count = DeliveryOrder::deliveryOrders()->whereYear('created_at', $year)->count() + 1;
        $doNumber = 'DO' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);

        DeliveryOrder::create([
            'do_number'          => $doNumber,
            'document_type'      => 'do',
            'counterparty_type'  => 'pertamina',
            'counterparty_name'  => $so->counterparty_name ?: 'Pertamina',
            'so_number'          => $so->do_number,
            'order_type'         => 'supplier',
            'origin_branch_id'   => $mainBranch?->id,
            'cylinder_type'      => $so->cylinder_type,
            'quantity_ordered'   => $so->quantity_ordered,
            'order_date'         => $so->order_date,
            'eta'                => $so->eta,
            'expedition_id'      => $so->expedition_id,
            'vehicle_id'         => $so->vehicle_id,
            'transportir_name'   => $so->transportir_name,
            'container_number'   => $so->container_number,
            'notes'              => "Auto-created from SO #{$so->do_number}",
            'requested_by'       => $so->requested_by,
            'status'             => 'draft',
        ]);
    }

    /**
     * T7-18: When a PO is approved, auto-create a DO for fulfillment.
     */
    private function createDeliveryOrderFromPo(DeliveryOrder $po): void
    {
        $mainBranch = Branch::mainBranch()->first();

        $year  = date('Y');
        $count = DeliveryOrder::deliveryOrders()->whereYear('created_at', $year)->count() + 1;
        $doNumber = 'DO' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);

        DeliveryOrder::create([
            'do_number'          => $doNumber,
            'document_type'      => 'do',
            'counterparty_type'  => 'branch',
            'counterparty_name'  => $po->counterparty_name ?: $po->destinationBranch?->name,
            'po_number'          => $po->do_number,
            'order_type'         => 'inter_branch',
            'origin_branch_id'   => $mainBranch?->id,
            'destination_branch_id' => $po->destination_branch_id,
            'cylinder_type'      => $po->cylinder_type,
            'quantity_ordered'   => $po->quantity_ordered,
            'order_date'         => $po->order_date,
            'eta'                => $po->eta,
            'expedition_id'      => $po->expedition_id,
            'vehicle_id'         => $po->vehicle_id,
            'transportir_name'   => $po->transportir_name,
            'container_number'   => $po->container_number,
            'notes'              => "Auto-created from PO #{$po->do_number}",
            'requested_by'       => $po->requested_by,
            'status'             => 'draft',
        ]);
    }

    private function notifyPendingApproval(DeliveryOrder $do): void
    {
        $recipients = User::whereIn('role', ['owner_pusat', 'regional_leader'])
            ->where('status', 'active')
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $docLabel = $do->document_type_label;

        Notification::make()
            ->title("📋 {$docLabel} Pending Approval")
            ->body("{$docLabel} #{$do->do_number} from {$do->destinationBranch?->name} requires approval. " . number_format($do->quantity_ordered) . " pcs of {$do->cylinder_type}.")
            ->warning()
            ->sendToDatabase($recipients);
    }

    private function notifyApproved(DeliveryOrder $do): void
    {
        // After Bug 1 fix: destination_branch_id = requesting branch that will receive stock
        $recipients = User::where('branch_id', $do->destination_branch_id)
            ->whereIn('role', ['owner_cabang', 'staff_gudang'])
            ->where('status', 'active')
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $docLabel = $do->document_type_label;

        Notification::make()
            ->title("✅ {$docLabel} Approved — Stock in Transit")
            ->body("{$docLabel} #{$do->do_number} for {$do->cylinder_type} ({$do->quantity_ordered} pcs) has been approved. Expect delivery soon.")
            ->success()
            ->sendToDatabase($recipients);
    }

    private function notifyOnTransportir(DeliveryOrder $do): void
    {
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

    private function handleDelivered(DeliveryOrder $do): void
    {
        // 1. Create stock mutations (origin OUT, destination IN)
        $this->createStockMutations($do);

        // 2. Update stock quantities for destination branch
        $this->updateStockItems($do);

        // 3. Notify destination branch
        $recipients = User::where('branch_id', $do->destination_branch_id)
            ->whereIn('role', ['owner_cabang', 'staff_gudang'])
            ->where('status', 'active')
            ->get();

        if ($recipients->isNotEmpty()) {
            $received = $do->quantity_received
                ? number_format($do->quantity_received) . ' pcs received'
                : 'delivered';

            Notification::make()
                ->title('📦 Delivery Arrived')
                ->body("DO #{$do->do_number} — {$received}. Stock has been updated.")
                ->success()
                ->sendToDatabase($recipients);
        }
    }

    private function createStockMutations(DeliveryOrder $do): void
    {
        $qty = $do->quantity_received ?? $do->quantity_ordered;
        $date = $do->received_date ?? today();
        $ref = $do->do_number;

        // Origin branch: OUT (HPP deduction)
        StockMutation::create([
            'branch_id'               => $do->origin_branch_id,
            'source_branch_id'        => null,
            'destination_branch_id'   => null,
            'cylinder_type'           => $do->cylinder_type,
            'mutation_type'           => 'out',
            'quantity'                => $qty,
            'reference_no'            => $ref,
            'notes'                   => "HPP for DO #{$ref} to {$do->destinationBranch?->name}",
            'mutation_date'           => $date,
            'created_by'              => auth()->id() ?? $do->requested_by,
        ]);

        // Destination branch: IN (stock receipt)
        StockMutation::create([
            'branch_id'               => $do->destination_branch_id,
            'source_branch_id'        => null,
            'destination_branch_id'   => null,
            'cylinder_type'           => $do->cylinder_type,
            'mutation_type'           => 'in',
            'quantity'                => $qty,
            'reference_no'            => $ref,
            'notes'                   => "Purchase/receipt from DO #{$ref} — {$do->originBranch?->name}",
            'mutation_date'           => $date,
            'created_by'              => auth()->id() ?? $do->requested_by,
        ]);
    }

    private function updateStockItems(DeliveryOrder $do): void
    {
        $qty = $do->quantity_received ?? $do->quantity_ordered;

        // Deduct from origin branch stock
        $originStock = StockItem::where('branch_id', $do->origin_branch_id)
            ->where('cylinder_type', $do->cylinder_type)
            ->whereDate('recorded_at', $do->received_date ?? today())
            ->first();

        if ($originStock) {
            $originStock->decrement('qty_full', $qty);
        }

        // Add to destination branch stock
        $destStock = StockItem::where('branch_id', $do->destination_branch_id)
            ->where('cylinder_type', $do->cylinder_type)
            ->whereDate('recorded_at', $do->received_date ?? today())
            ->first();

        if ($destStock) {
            $destStock->increment('qty_full', $qty);
        }
    }
}
