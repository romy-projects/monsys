<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailySaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'branch_id'     => $this->branch_id,
            'branch_name'   => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'customer_id'   => $this->customer_id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'cylinder_type' => $this->cylinder_type,
            'buyer_type'    => $this->buyer_type,
            'quantity'      => (int) $this->quantity,
            'selling_price' => (float) $this->selling_price,
            'lpg_price_id'  => $this->lpg_price_id,
            'purchase_price_at_sale' => $this->purchase_price_at_sale === null ? null : (float) $this->purchase_price_at_sale,
            'lpg_price'     => $this->whenLoaded('lpgPrice', fn () => [
                'id' => $this->lpgPrice?->id,
                'branch_id' => $this->lpgPrice?->branch_id,
                'effective_date' => $this->lpgPrice?->effective_date?->toDateString(),
            ]),
            'total_revenue' => (float) $this->total_revenue,
            'sale_date'     => $this->sale_date?->toDateString(),
            'notes'         => $this->notes,
            'created_by'    => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at'    => $this->created_at?->toDateTimeString(),
        ];
    }
}
