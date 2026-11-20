<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReconciliationSettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'party'                    => $this->party,
            'settlement_type'          => $this->settlement_type,
            'type_label'               => $this->type_label,
            'quantity'                 => (int) $this->quantity,
            'unit_price'               => (float) $this->unit_price,
            'amount'                   => (float) $this->amount,
            'source_purchase_order_id' => $this->source_purchase_order_id,
            'source_purchase_order_number' => $this->whenLoaded(
                'sourcePurchaseOrder',
                fn () => $this->sourcePurchaseOrder?->do_number
            ),
            'applies_to_next_delivery' => (bool) $this->applies_to_next_delivery,
            'status'                   => $this->status,
            'settled_by_type'          => $this->settled_by_type,
            'settled_by_id'            => $this->settled_by_id,
            'settled_document'         => $this->settled_document_label,
            'settled_at'               => $this->settled_at?->toDateTimeString(),
            'notes'                    => $this->notes,
            'created_at'               => $this->created_at?->toDateTimeString(),
        ];
    }
}
