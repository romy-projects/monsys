<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'code'       => $this->code,
            'name'       => $this->name,
            'city'       => $this->city,
            'province'   => $this->province,
            'address'    => $this->address,
            'phone'      => $this->phone,
            'status'     => $this->status,
            'regional_id'=> $this->regional_id,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
