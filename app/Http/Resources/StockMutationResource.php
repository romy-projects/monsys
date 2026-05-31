<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMutationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'branch_id'           => $this->branch_id,
            'branch_name'         => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'source_branch_id'    => $this->source_branch_id,
            'destination_branch_id' => $this->destination_branch_id,
            'cylinder_type'       => $this->cylinder_type,
            'mutation_type'       => $this->mutation_type,
            'quantity'            => (int) $this->quantity,
            'reference_no'        => $this->reference_no,
            'notes'               => $this->notes,
            'mutation_date'       => $this->mutation_date?->toDateString(),
            'created_by'          => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at'          => $this->created_at?->toDateTimeString(),
        ];
    }
}
