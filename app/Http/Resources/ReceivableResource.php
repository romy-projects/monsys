<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceivableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'branch_id'   => $this->branch_id,
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'buyer_name'  => $this->buyer_name,
            'buyer_type'  => $this->buyer_type,
            'amount'      => (float) $this->amount,
            'paid_amount' => (float) $this->paid_amount,
            'balance'     => (float) $this->balance,
            'due_date'    => $this->due_date?->toDateString(),
            'status'      => $this->status,
            'notes'       => $this->notes,
            'days_overdue'=> $this->due_date && $this->status !== 'paid'
                ? max(0, now()->diffInDays($this->due_date, false) * -1)
                : 0,
            'created_at'  => $this->created_at?->toDateTimeString(),
        ];
    }
}
