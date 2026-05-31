<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'branch_id'     => $this->branch_id,
            'branch_name'   => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'cylinder_type' => $this->cylinder_type,
            'qty_full'      => (int) $this->qty_full,
            'qty_empty'     => (int) $this->qty_empty,
            'qty_damaged'   => (int) $this->qty_damaged,
            'is_low_stock'  => $this->qty_full < 20,
            'alert_level'   => $this->qty_full < 20 ? 'danger' : ($this->qty_full < 50 ? 'warning' : 'ok'),
            'recorded_at'   => $this->recorded_at?->toDateString(),
            'updated_at'    => $this->updated_at?->toDateTimeString(),
        ];
    }
}
