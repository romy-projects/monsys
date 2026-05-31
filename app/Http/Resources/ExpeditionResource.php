<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpeditionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'code'           => $this->code,
            'phone'          => $this->phone,
            'contact_person' => $this->contact_person,
            'status'         => $this->status,
            'created_at'     => $this->created_at?->toDateTimeString(),
        ];
    }
}
