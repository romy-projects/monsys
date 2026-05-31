<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryOrderResource;
use App\Http\Traits\ApiResponse;
use App\Models\DeliveryOrder;
use App\Models\StockClose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryOrderController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = DeliveryOrder::query()
            ->with(['originBranch', 'destinationBranch', 'expedition', 'vehicle']);

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where(fn ($q) => $q
                ->where('origin_branch_id', $user->branch_id)
                ->orWhere('destination_branch_id', $user->branch_id)
            );
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('order_type')) {
            $query->where('order_type', $request->order_type);
        }
        if ($request->filled('cylinder_type')) {
            $query->where('cylinder_type', $request->cylinder_type);
        }
        if ($request->filled('branch_id')) {
            $query->where(fn ($q) => $q
                ->where('origin_branch_id', $request->branch_id)
                ->orWhere('destination_branch_id', $request->branch_id)
            );
        }
        if ($request->filled('from')) {
            $query->whereDate('order_date', '>=', $request->from);
        }
        if ($request->filled('until')) {
            $query->whereDate('order_date', '<=', $request->until);
        }

        return $this->paginated($query->orderByDesc('order_date')->paginate(30));
    }

    public function show(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeView($user, $deliveryOrder);

        return $this->success(
            new DeliveryOrderResource($deliveryOrder->load('originBranch', 'destinationBranch', 'expedition', 'vehicle', 'requestedBy', 'approvedBy'))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Golden rule: branch staff must submit stock close first
        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            if (! StockClose::isTodaySubmitted($user->branch_id)) {
                return $this->error("Today's stock close must be submitted before creating a Delivery Order.", 422);
            }
        }

        $data = $request->validate([
            'order_type'             => ['required', 'in:inter_branch,supplier'],
            'do_number'              => ['required', 'string', 'max:50', 'unique:delivery_orders,do_number'],
            'order_date'             => ['required', 'date'],
            'cylinder_type'          => ['required', 'in:3kg,5.5kg,12kg,50kg'],
            'quantity_ordered'       => ['required', 'integer', 'min:1'],
            'destination_branch_id'  => ['required', 'exists:branches,id'],
            'origin_branch_id'       => ['nullable', 'exists:branches,id'],
            'supplier_name'          => ['nullable', 'string', 'max:200'],
            'expedition_id'          => ['nullable', 'exists:expeditions,id'],
            'vehicle_id'             => ['nullable', 'exists:vehicles,id'],
            'container_number'       => ['nullable', 'string', 'max:100'],
            'eta'                    => ['nullable', 'date'],
            'notes'                  => ['nullable', 'string'],
        ]);

        $data['requested_by'] = $user->id;
        $data['status']       = 'draft';

        if ($data['order_type'] === 'supplier') {
            unset($data['origin_branch_id']);
        }

        $do = DeliveryOrder::create($data);

        return $this->created(
            new DeliveryOrderResource($do->load('originBranch', 'destinationBranch', 'expedition', 'vehicle'))
        );
    }

    public function update(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($deliveryOrder->status !== 'draft') {
            return $this->error('Only draft orders can be edited.', 422);
        }

        $data = $request->validate([
            'order_type'            => ['sometimes', 'in:inter_branch,supplier'],
            'cylinder_type'         => ['sometimes', 'in:3kg,5.5kg,12kg,50kg'],
            'quantity_ordered'      => ['sometimes', 'integer', 'min:1'],
            'destination_branch_id' => ['sometimes', 'exists:branches,id'],
            'origin_branch_id'      => ['nullable', 'exists:branches,id'],
            'supplier_name'         => ['nullable', 'string', 'max:200'],
            'expedition_id'         => ['nullable', 'exists:expeditions,id'],
            'vehicle_id'            => ['nullable', 'exists:vehicles,id'],
            'container_number'      => ['nullable', 'string', 'max:100'],
            'eta'                   => ['nullable', 'date'],
            'notes'                 => ['nullable', 'string'],
        ]);

        $deliveryOrder->update($data);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()->load('originBranch', 'destinationBranch', 'expedition', 'vehicle')));
    }

    // ── Workflow Actions ──────────────────────────────────────

    public function submit(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        if ($deliveryOrder->status !== 'draft') {
            return $this->error('Only draft orders can be submitted.', 422);
        }

        $deliveryOrder->update(['status' => 'pending_approval']);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()), 'Submitted for approval.');
    }

    public function approve(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->canApproveOrders()) {
            return $this->forbidden();
        }
        if ($deliveryOrder->status !== 'pending_approval') {
            return $this->error('Only pending orders can be approved.', 422);
        }

        $deliveryOrder->update([
            'status'      => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()), 'Approved.');
    }

    public function markInTransit(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->canApproveOrders()) {
            return $this->forbidden();
        }
        if ($deliveryOrder->status !== 'approved') {
            return $this->error('Only approved orders can be marked in transit.', 422);
        }

        $deliveryOrder->update(['status' => 'in_transit']);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()), 'Marked in transit.');
    }

    public function receive(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        if ($deliveryOrder->status !== 'in_transit') {
            return $this->error('Only in-transit orders can be received.', 422);
        }

        $data = $request->validate([
            'quantity_received' => ['required', 'integer', 'min:0'],
            'received_date'     => ['required', 'date'],
        ]);

        $deliveryOrder->update(array_merge($data, ['status' => 'delivered']));

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()), 'Delivery confirmed.');
    }

    public function cancel(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->canApproveOrders()) {
            return $this->forbidden();
        }
        if (! in_array($deliveryOrder->status, ['draft', 'pending_approval'])) {
            return $this->error('Only draft or pending orders can be cancelled.', 422);
        }

        $deliveryOrder->update(['status' => 'cancelled']);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()), 'Cancelled.');
    }

    private function authorizeView($user, DeliveryOrder $do): void
    {
        if ($user->isOwnerPusat() || $user->isRegionalLeader()) return;
        if ($do->origin_branch_id === $user->branch_id) return;
        if ($do->destination_branch_id === $user->branch_id) return;
        abort(403);
    }
}
