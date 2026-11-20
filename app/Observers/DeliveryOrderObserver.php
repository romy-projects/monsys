<?php

namespace App\Observers;

use App\Models\CylinderCirculation;
use App\Models\DeliveryOrder;
use App\Models\StockItem;
use App\Models\StockMutation;
use App\Models\User;
use App\Services\CylinderReconciliationService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

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
        // The Delivery Order (document_type = 'do') flow is retired (N-GEN-7).
        // Approving an SO no longer spawns a DO: the canonical procurement flow is
        // PO (branch requests HQ) → SO (HQ redeems at Pertamina) → LO (transportir/expedition).
        $this->notifyApproved($do);
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

        $isPurchaseOrder = $do->document_type === 'po';
        $docLabel = $do->document_type_label;
        $title = $isPurchaseOrder
            ? '✅ Purchase Order Approved'
            : "✅ {$docLabel} Approved — Stock in Transit";
        $body = $isPurchaseOrder
            ? "PO #{$do->do_number} for {$do->cylinder_type} ({$do->quantity_ordered} pcs) has been approved. Track fulfillment on this PO."
            : "{$docLabel} #{$do->do_number} for {$do->cylinder_type} ({$do->quantity_ordered} pcs) has been approved. Expect delivery soon.";

        Notification::make()
            ->title($title)
            ->body($body)
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

        $documentLabel = $do->document_type === 'po' ? 'PO' : 'DO';

        Notification::make()
            ->title("🚛 {$documentLabel} On Transportir")
            ->body("{$documentLabel} #{$do->do_number} is now with the transportir. ETA: " . ($do->eta?->format('d M Y') ?? 'N/A') . ".")
            ->warning()
            ->sendToDatabase($recipients);
    }

    private function handleDelivered(DeliveryOrder $do): void
    {
        if ($do->document_type === 'po') {
            $this->handlePurchaseOrderReceipt($do);
        } else {
            if ($do->document_type === 'lo') {
                // BALI-18: a delivered LO reconciles PO/SO/LO quantities (idempotent, BALI-22).
                app(CylinderReconciliationService::class)->reconcile($do);
            }

            // Preserve the established non-PO delivery workflows.
            $this->createStockMutations($do);
            $this->updateStockItems($do);
        }

        // 3. Notify destination branch
        $recipients = User::where('branch_id', $do->destination_branch_id)
            ->whereIn('role', ['owner_cabang', 'staff_gudang'])
            ->where('status', 'active')
            ->get();

        if ($recipients->isNotEmpty()) {
            $received = $do->quantity_received !== null
                ? number_format($do->quantity_received) . ' pcs received'
                : 'delivered';
            $documentLabel = $do->document_type === 'po' ? 'PO' : 'DO';

            Notification::make()
                ->title('📦 Delivery Arrived')
                ->body("{$documentLabel} #{$do->do_number} — {$received}. Stock has been updated.")
                ->success()
                ->sendToDatabase($recipients);
        }
    }

    private function handlePurchaseOrderReceipt(DeliveryOrder $do): void
    {
        DB::transaction(function () use ($do) {
            $po = DeliveryOrder::query()
                ->whereKey($do->getKey())
                ->lockForUpdate()
                ->first();

            if (! $po || $po->document_type !== 'po' || $po->status !== 'delivered') {
                return;
            }

            if (! $po->origin_branch_id || ! $po->destination_branch_id ||
                $po->origin_branch_id === $po->destination_branch_id) {
                return;
            }

            $quantity = $po->quantity_received ?? $po->quantity_ordered;
            $date = $po->received_date ?? today();
            $createdBy = auth()->id() ?? $po->requested_by;

            foreach ([
                ['branch_id' => $po->origin_branch_id, 'type' => 'out'],
                ['branch_id' => $po->destination_branch_id, 'type' => 'in'],
            ] as $movement) {
                $alreadyRecorded = StockMutation::query()
                    ->where('branch_id', $movement['branch_id'])
                    ->where('mutation_type', $movement['type'])
                    ->where(function ($query) use ($po) {
                        $query->where('purchase_order_id', $po->id)
                            ->orWhere(function ($legacy) use ($po) {
                                $legacy->whereNull('purchase_order_id')
                                    ->where('reference_no', $po->do_number);
                            });
                    })
                    ->exists();

                if ($alreadyRecorded) {
                    continue;
                }

                StockMutation::create([
                    'purchase_order_id'    => $po->id,
                    'branch_id'            => $movement['branch_id'],
                    'source_branch_id'     => $movement['type'] === 'out' ? $po->origin_branch_id : null,
                    'destination_branch_id' => $movement['type'] === 'in' ? $po->destination_branch_id : null,
                    'cylinder_type'        => $po->cylinder_type,
                    'mutation_type'        => $movement['type'],
                    'quantity'             => $quantity,
                    'reference_no'         => $po->do_number,
                    'notes'                => $movement['type'] === 'out'
                        ? "HQ stock fulfilled PO #{$po->do_number} for {$po->destinationBranch?->name}"
                        : "Receipt for PO #{$po->do_number} from {$po->originBranch?->name}",
                    'mutation_date'        => $date,
                    'created_by'           => $createdBy,
                ]);

                $this->updateStockItemForReceipt(
                    $movement['branch_id'],
                    $po->cylinder_type,
                    $date,
                    $quantity,
                    $movement['type'] === 'in'
                );
            }

            $circulationExists = CylinderCirculation::query()
                ->where('branch_id', $po->destination_branch_id)
                ->where('transaction_type', 'kirim')
                ->where(function ($query) use ($po) {
                    $query->where('purchase_order_id', $po->id)
                        ->orWhere('so_number', $po->do_number);
                })
                ->exists();

            if (! $circulationExists) {
                CylinderCirculation::create([
                    'purchase_order_id' => $po->id,
                    'branch_id'          => $po->destination_branch_id,
                    'transaction_date'   => $date,
                    'so_number'          => $po->do_number,
                    'transaction_type'   => 'kirim',
                    'description'        => "PO #{$po->do_number} received from HQ",
                    'cylinder_type'      => $po->cylinder_type,
                    'direction'          => 'debit',
                    'quantity'           => $quantity,
                    'notes'              => "Received against PO #{$po->do_number}",
                    'created_by'         => $createdBy,
                ]);
            }
        });
    }

    private function updateStockItemForReceipt(
        int $branchId,
        string $cylinderType,
        $date,
        int $quantity,
        bool $incoming
    ): void {
        $stock = StockItem::query()
            ->where('branch_id', $branchId)
            ->where('cylinder_type', $cylinderType)
            ->whereDate('recorded_at', $date)
            ->first();

        if (! $stock) {
            return;
        }

        $stock->increment('qty_full', $incoming ? $quantity : -$quantity);
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
