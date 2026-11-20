<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayablePaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'payable_id'        => $this->payable_id,
            'delivery_order_id' => $this->delivery_order_id,
            'lo_number'         => $this->whenLoaded('deliveryOrder', fn () => $this->deliveryOrder?->do_number),
            'paid_on'           => $this->paid_on?->toDateString(),
            'amount'            => (float) $this->amount,
            'method'            => $this->method,
            'method_label'      => $this->method_label,
            'reference_no'      => $this->reference_no,
            'notes'             => $this->notes,
            'created_by'        => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at'        => $this->created_at?->toDateTimeString(),
        ];
    }
}
