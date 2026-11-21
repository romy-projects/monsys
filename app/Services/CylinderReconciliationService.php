<?php

namespace App\Services;

use App\Models\CylinderReconciliation;
use App\Models\DeliveryOrder;
use App\Models\LpgPrice;
use App\Models\ReconciliationSettlement;
use Illuminate\Support\Facades\DB;

/**
 * BALI-18…22: reconcile a delivered Loading Order against the PO/SO it fulfils.
 *
 * The chain is PO (branch paid/requested) → SO (HQ redeemed at Pertamina) → LO
 * (transportir delivered). Per decision D4, `quantity_ordered` is the authoritative
 * reconcile quantity on every leg; `unit_price` is the PO purchase-price snapshot (D5).
 *
 * Cases:
 *   Case 1 — paid 100, redeemed 90, delivered 90 → HQ owes the branch for 10.
 *   Case 2 — paid 100, redeemed 100, delivered 95 → HQ owes the branch 5 AND
 *            claims those 5 from the transportir.
 *   Case 3 — paid 100, redeemed 100, delivered 105 → HQ claims the extra 5 from
 *            the branch on its next delivery, at this PO's unit price.
 *
 * Every write is idempotent (BALI-22): the reconciliation is unique per PO+LO and
 * each settlement is unique per (reconciliation, party, settlement_type).
 */
class CylinderReconciliationService
{
    /**
     * Reconcile a delivered LO. Returns null when there is nothing to reconcile
     * (not an LO, not delivered, or no PO reference) — never a false positive.
     */
    public function reconcile(DeliveryOrder $lo, ?int $createdBy = null): ?CylinderReconciliation
    {
        if ($lo->document_type !== 'lo' || $lo->status !== 'delivered') {
            return null;
        }

        if (blank($lo->po_number)) {
            return null;
        }

        $po = DeliveryOrder::purchaseOrders()
            ->where('do_number', $lo->po_number)
            ->first();

        if (! $po) {
            return null;
        }

        $so = $this->resolveSalesOrder($lo, $po);

        $branchId = $lo->destination_branch_id ?: $po->destination_branch_id;

        if (! $branchId) {
            return null;
        }

        $paid = (int) $po->quantity_ordered;
        $redeemed = $so ? (int) $so->quantity_ordered : $paid;
        $delivered = (int) $lo->quantity_ordered;

        $unredeemed = max(0, $paid - $redeemed);
        $shortDelivered = max(0, $redeemed - $delivered);
        $overDelivered = max(0, $delivered - $redeemed);

        $unitPrice = $this->purchasePrice($po, (int) $branchId);

        $caseType = match (true) {
            $shortDelivered > 0 => 'case_2',
            $unredeemed > 0 => 'case_1',
            $overDelivered > 0 => 'case_3',
            default => 'balanced',
        };

        [$reconciliation, $notify] = DB::transaction(function () use (
            $lo, $po, $so, $branchId, $paid, $redeemed, $delivered,
            $unredeemed, $shortDelivered, $overDelivered, $unitPrice, $caseType, $createdBy
        ): array {
            $previous = CylinderReconciliation::query()
                ->where('purchase_order_id', $po->id)
                ->where('loading_order_id', $lo->id)
                ->first();

            $reconciliation = CylinderReconciliation::updateOrCreate(
                [
                    'purchase_order_id' => $po->id,
                    'loading_order_id' => $lo->id,
                ],
                [
                    'sales_order_id' => $so?->id,
                    'branch_id' => $branchId,
                    'cylinder_type' => $lo->cylinder_type ?: $po->cylinder_type,
                    'quantity_paid' => $paid,
                    'quantity_redeemed' => $redeemed,
                    'quantity_delivered' => $delivered,
                    'quantity_unredeemed' => $unredeemed,
                    'quantity_short_delivered' => $shortDelivered,
                    'quantity_over_delivered' => $overDelivered,
                    'case_type' => $caseType,
                    'unit_price' => $unitPrice,
                ]
            );

            if ($reconciliation->created_by === null) {
                $reconciliation->forceFill(['created_by' => $createdBy ?? auth()->id()])->save();
            }

            $this->syncSettlements(
                $reconciliation,
                $po,
                $unitPrice,
                $unredeemed,
                $shortDelivered,
                $overDelivered
            );

            return [
                $reconciliation->fresh(['settlements', 'branch', 'purchaseOrder', 'salesOrder', 'loadingOrder']),
                $this->isMaterialChange($previous, $paid, $redeemed, $delivered, $caseType, $unitPrice),
            ];
        });

        // T12-08b: HQ and the owning branch hear about a new discrepancy exactly
        // once — a reprocess that changes nothing stays silent (BALI-22).
        if ($notify) {
            app(ReconciliationNotifier::class)->notifyDiscrepancy($reconciliation);
        }

        return $reconciliation;
    }

    /**
     * T12-08b: only a new or numerically changed reconciliation is worth a
     * notification; re-running the trigger on unchanged data must not re-notify.
     */
    private function isMaterialChange(
        ?CylinderReconciliation $previous,
        int $paid,
        int $redeemed,
        int $delivered,
        string $caseType,
        float $unitPrice
    ): bool {
        if ($previous === null) {
            return true;
        }

        return (int) $previous->quantity_paid !== $paid
            || (int) $previous->quantity_redeemed !== $redeemed
            || (int) $previous->quantity_delivered !== $delivered
            || $previous->case_type !== $caseType
            || (float) $previous->unit_price !== $unitPrice;
    }

    /**
     * Prefer the SO the LO names, then the latest SO redeemed against this PO.
     */
    private function resolveSalesOrder(DeliveryOrder $lo, DeliveryOrder $po): ?DeliveryOrder
    {
        if (! blank($lo->so_number)) {
            $so = DeliveryOrder::salesOrders()->where('do_number', $lo->so_number)->first();

            if ($so) {
                return $so;
            }
        }

        return DeliveryOrder::salesOrders()
            ->where(function ($query) use ($po) {
                $query->where('po_number', $po->do_number)
                    ->orWhere('sales_order_id', $po->id);
            })
            ->orderByDesc('order_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * PO purchase-price snapshot: the price row linked to the PO, else the price
     * effective at the PO date for that branch, else 0 (no price configured).
     */
    private function purchasePrice(DeliveryOrder $po, int $branchId): float
    {
        $linked = LpgPrice::query()
            ->where('purchase_order_id', $po->id)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();

        if ($linked) {
            return (float) $linked->purchase_price;
        }

        $dated = LpgPrice::priceAtDate(
            $po->cylinder_type,
            $po->order_date?->toDateString(),
            $branchId
        );

        return $dated ? (float) $dated->purchase_price : 0.0;
    }

    /**
     * Mirror the discrepancies into settlement rows. Existing rows are updated in
     * place (never duplicated); open rows that no longer apply are removed.
     */
    private function syncSettlements(
        CylinderReconciliation $reconciliation,
        DeliveryOrder $po,
        float $unitPrice,
        int $unredeemed,
        int $shortDelivered,
        int $overDelivered
    ): void {
        $desired = [];

        if ($unredeemed > 0) {
            $desired[ReconciliationSettlement::TYPE_UNREDEEMED_CREDIT] = [
                'party' => ReconciliationSettlement::PARTY_HQ,
                'quantity' => $unredeemed,
                'notes' => "HQ redeemed {$reconciliation->quantity_redeemed} of {$reconciliation->quantity_paid} paid units on PO #{$po->do_number}.",
            ];
        }

        if ($shortDelivered > 0) {
            $desired[ReconciliationSettlement::TYPE_SHORT_DELIVERY_CREDIT] = [
                'party' => ReconciliationSettlement::PARTY_HQ,
                'quantity' => $shortDelivered,
                'notes' => "Transportir delivered {$reconciliation->quantity_delivered} of {$reconciliation->quantity_redeemed} redeemed units on PO #{$po->do_number}.",
            ];
            $desired[ReconciliationSettlement::TYPE_TRANSPORTIR_CLAIM] = [
                'party' => ReconciliationSettlement::PARTY_TRANSPORTIR,
                'quantity' => $shortDelivered,
                'notes' => "Transportir short-delivered {$shortDelivered} units against LO #{$po->do_number}.",
            ];
        }

        if ($overDelivered > 0) {
            $desired[ReconciliationSettlement::TYPE_OVER_DELIVERY_CLAIM] = [
                'party' => ReconciliationSettlement::PARTY_BRANCH,
                'quantity' => $overDelivered,
                'notes' => "Branch received {$overDelivered} extra units; claimed on its next delivery at PO #{$po->do_number} price.",
                'source_purchase_order_id' => $po->id,
                'applies_to_next_delivery' => true,
            ];
        }

        foreach ($desired as $type => $attributes) {
            ReconciliationSettlement::updateOrCreate(
                [
                    'reconciliation_id' => $reconciliation->id,
                    'settlement_type' => $type,
                ],
                $attributes + [
                    'unit_price' => $unitPrice,
                    'amount' => round($attributes['quantity'] * $unitPrice, 2),
                ]
            );
        }

        // Drop stale open obligations: the data was reprocessed and the discrepancy vanished.
        ReconciliationSettlement::query()
            ->where('reconciliation_id', $reconciliation->id)
            ->where('status', 'open')
            ->when(
                $desired !== [],
                fn ($query) => $query->whereNotIn('settlement_type', array_keys($desired))
            )
            ->delete();
    }
}
