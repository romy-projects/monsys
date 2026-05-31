<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockCloseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'branch_id'     => $this->branch_id,
            'branch_name'   => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'close_date'    => $this->close_date?->toDateString(),
            'cylinder_type' => $this->cylinder_type,
            'qty_full'      => (int) $this->qty_full,
            'qty_empty'     => (int) $this->qty_empty,
            'qty_damaged'   => (int) $this->qty_damaged,
            'status'        => $this->status,
            'submitted_at'  => $this->submitted_at?->toDateTimeString(),
            'verified_at'   => $this->verified_at?->toDateTimeString(),
            'submitted_by'  => $this->whenLoaded('submittedBy', fn () => $this->submittedBy?->name),
            'verified_by'   => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy?->name),
            'created_at'    => $this->created_at?->toDateTimeString(),
        ];
    }
}
