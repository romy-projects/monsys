<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'do_number'          => $this->do_number,
            'order_type'         => $this->order_type,
            'supplier_name'      => $this->supplier_name,
            'cylinder_type'      => $this->cylinder_type,
            'quantity_ordered'   => (int) $this->quantity_ordered,
            'quantity_received'  => $this->quantity_received ? (int) $this->quantity_received : null,
            'container_number'   => $this->container_number,
            'order_date'         => $this->order_date?->toDateString(),
            'eta'                => $this->eta?->toDateString(),
            'received_date'      => $this->received_date?->toDateString(),
            'status'             => $this->status,
            'notes'              => $this->notes,
            'approved_at'        => $this->approved_at?->toDateTimeString(),
            'origin_branch'      => $this->whenLoaded('originBranch', fn () => $this->order_type === 'supplier'
                ? ['id' => null, 'name' => $this->supplier_name ?? 'Supplier']
                : ['id' => $this->originBranch?->id, 'name' => $this->originBranch?->name]),
            'destination_branch' => $this->whenLoaded('destinationBranch', fn () => [
                'id'   => $this->destinationBranch?->id,
                'name' => $this->destinationBranch?->name,
            ]),
            'expedition'         => $this->whenLoaded('expedition', fn () => $this->expedition ? [
                'id'   => $this->expedition->id,
                'name' => $this->expedition->name,
            ] : null),
            'vehicle'            => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id'           => $this->vehicle->id,
                'plate_number' => $this->vehicle->plate_number,
                'driver_name'  => $this->vehicle->driver_name,
            ] : null),
            'requested_by'       => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            'approved_by'        => $this->whenLoaded('approvedBy', fn () => $this->approvedBy?->name),
            'created_at'         => $this->created_at?->toDateTimeString(),
        ];
    }
}
