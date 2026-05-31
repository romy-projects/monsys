<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'branch_id'    => $this->branch_id,
            'branch_name'  => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'name'         => $this->name,
            'type'         => $this->type,
            'phone'        => $this->phone,
            'address'      => $this->address,
            'credit_limit' => (float) $this->credit_limit,
            'notes'        => $this->notes,
            'is_active'    => (bool) $this->is_active,
            'created_at'   => $this->created_at?->toDateTimeString(),
        ];
    }
}
