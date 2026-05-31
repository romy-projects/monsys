<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OperationalCostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'branch_id'     => $this->branch_id,
            'branch_name'   => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'cost_category' => $this->cost_category,
            'description'   => $this->description,
            'amount'        => (float) $this->amount,
            'cost_date'     => $this->cost_date?->toDateString(),
            'notes'         => $this->notes,
            'created_by'    => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at'    => $this->created_at?->toDateTimeString(),
        ];
    }
}
