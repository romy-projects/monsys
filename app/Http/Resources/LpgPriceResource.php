<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LpgPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'cylinder_type'  => $this->cylinder_type,
            'purchase_price' => (float) $this->purchase_price,
            'selling_price'  => (float) $this->selling_price,
            'effective_date' => $this->effective_date?->toDateString(),
            'created_by'     => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at'     => $this->created_at?->toDateTimeString(),
        ];
    }
}
