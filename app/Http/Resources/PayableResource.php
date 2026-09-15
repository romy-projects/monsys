<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'transportir_id'    => $this->transportir_id,
            'transportir'       => $this->whenLoaded('transportir', fn() => [
                'id'   => $this->transportir->id,
                'name' => $this->transportir->name,
            ]),
            'delivery_order_id' => $this->delivery_order_id,
            'delivery_order'    => $this->whenLoaded('deliveryOrder', fn() => [
                'id'        => $this->deliveryOrder->id,
                'do_number' => $this->deliveryOrder->do_number,
            ]),
            'invoice_number' => $this->invoice_number,
            'description'    => $this->description,
            'amount'         => (float) $this->amount,
            'paid_amount'    => (float) $this->paid_amount,
            'balance'        => (float) $this->balance,
            'due_date'       => $this->due_date?->toDateString(),
            'status'         => $this->status,
            'paid_at'        => $this->paid_at?->toDateTimeString(),
            'created_at'     => $this->created_at?->toDateTimeString(),
        ];
    }
}
