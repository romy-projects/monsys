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
            'total_revenue' => (float) $this->total_revenue,
            'sale_date'     => $this->sale_date?->toDateString(),
            'notes'         => $this->notes,
            'created_by'    => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at'    => $this->created_at?->toDateTimeString(),
        ];
    }
}
