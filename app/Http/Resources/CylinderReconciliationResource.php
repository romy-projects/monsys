<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CylinderReconciliationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'case_type' => $this->case_type,
            'case_label' => $this->case_label,
            'status' => $this->status,
            'cylinder_type' => $this->cylinder_type,
            'quantity_paid' => (int) $this->quantity_paid,
            'quantity_redeemed' => (int) $this->quantity_redeemed,
            'quantity_delivered' => (int) $this->quantity_delivered,
            'quantity_unredeemed' => (int) $this->quantity_unredeemed,
            'quantity_short_delivered' => (int) $this->quantity_short_delivered,
            'quantity_over_delivered' => (int) $this->quantity_over_delivered,
            'unit_price' => (float) $this->unit_price,
            'purchase_order_id' => $this->purchase_order_id,
            'loading_order_id' => $this->loading_order_id,
            'sales_order_id' => $this->sales_order_id,
            'branch_id' => $this->branch_id,
            'purchase_order' => $this->whenLoaded('purchaseOrder', fn () => [
                'id' => $this->purchaseOrder?->id,
                'do_number' => $this->purchaseOrder?->do_number,
                'order_date' => $this->purchaseOrder?->order_date?->toDateString(),
            ]),
            'loading_order' => $this->whenLoaded('loadingOrder', fn () => [
                'id' => $this->loadingOrder?->id,
                'do_number' => $this->loadingOrder?->do_number,
                'lo_kind' => $this->loadingOrder?->lo_kind,
                'received_date' => $this->loadingOrder?->received_date?->toDateString(),
            ]),
            'sales_order' => $this->whenLoaded('salesOrder', fn () => $this->salesOrder ? [
                'id' => $this->salesOrder->id,
                'do_number' => $this->salesOrder->do_number,
            ] : null),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ] : null),
            'settlements' => ReconciliationSettlementResource::collection($this->whenLoaded('settlements')),
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
