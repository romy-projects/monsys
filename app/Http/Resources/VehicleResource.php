<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'expedition_id'   => $this->expedition_id,
            'expedition_name' => $this->whenLoaded('expedition', fn() => $this->expedition?->name),
            'plate_number'    => $this->plate_number,
            'type'            => $this->type,
            'driver_name'     => $this->driver_name,
            'driver_phone'    => $this->driver_phone,
            'capacity_kg'     => $this->capacity_kg ? (float) $this->capacity_kg : null,
            'status'          => $this->status,
            'created_at'      => $this->created_at?->toDateTimeString(),
        ];
    }
}
