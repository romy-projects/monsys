<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CylinderCirculationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'branch_id'        => $this->branch_id,
            'branch'           => $this->whenLoaded('branch', fn() => [
                'id'   => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'transaction_date' => $this->transaction_date?->toDateString(),
            'so_number'        => $this->so_number,
            'transaction_type' => $this->transaction_type,
            'description'      => $this->description,
            'cylinder_type'    => $this->cylinder_type,
            'direction'        => $this->direction,
            'quantity'         => (int) $this->quantity,
            'container_no'     => $this->container_no,
            'handled_by'       => $this->handled_by,
            'notes'            => $this->notes,
            'created_by'       => $this->whenLoaded('createdBy', fn() => $this->createdBy?->name),
            'created_at'       => $this->created_at?->toDateTimeString(),
        ];
    }
}
