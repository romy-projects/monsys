<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'phone' => $this->phone,
            'status' => $this->status,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
            ]),
            'permissions' => [
                'can_approve_orders' => $this->canApproveOrders(),
                'can_view_finance' => $this->canViewFinance(),
                'is_owner_pusat' => $this->isOwnerPusat(),
                'is_regional_leader' => $this->isRegionalLeader(),
            ],
            'must_change_password' => (bool) $this->must_change_password,
            'password_changed_at' => $this->password_changed_at?->toDateTimeString(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
