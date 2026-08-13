<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryOrderResource;
use App\Http\Traits\ApiResponse;
use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\StockClose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    use ApiResponse;

    /**
     * List Purchase Orders (PO) — Other Branches requesting Tabung from Main Branch.
     */
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = DeliveryOrder::purchaseOrders()
            ->with(['originBranch', 'destinationBranch', 'expedition', 'vehicle']);

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where('destination_branch_id', $user->branch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('cylinder_type')) {
            $query->where('cylinder_type', $request->cylinder_type);
        }
        if ($request->filled('from')) {
            $query->whereDate('order_date', '>=', $request->from);
        }
        if ($request->filled('until')) {
            $query->whereDate('order_date', '<=', $request->until);
        }

        return $this->paginated($query->orderByDesc('order_date')->paginate(30));
    }

    /**
     * Show a single Purchase Order.
     */
    public function show(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeView($user, $deliveryOrder);

        return $this->success(
            new DeliveryOrderResource($deliveryOrder->load('originBranch', 'destinationBranch', 'expedition', 'vehicle', 'requestedBy', 'approvedBy'))
        );
    }

    /**
     * Create a Purchase Order (PO) from an Other Branch to Main Branch.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Only Other Branch users can create POs — Pusat/Regional cannot
        if ($user->isOwnerPusat() || $user->isRegionalLeader()) {
            return $this->forbidden('Only Other Branch users can create Purchase Orders.');
        }

        // Golden rule: branch staff must submit stock close first
        if (! StockClose::isTodaySubmitted($user->branch_id)) {
            return $this->error("Today's stock close must be submitted before creating a Purchase Order.", 422);
        }

        $mainBranch = Branch::mainBranch()->first();

        $data = $request->validate([
            'do_number'         => ['required', 'string', 'max:50', 'unique:delivery_orders,do_number'],
            'order_date'        => ['required', 'date'],
            'cylinder_type'     => ['required', 'in:3kg,5.5kg,12kg,50kg'],
            'quantity_ordered'  => ['required', 'integer', 'min:1'],
            'counterparty_name' => ['nullable', 'string', 'max:200'],
            'expedition_id'     => ['nullable', 'exists:expeditions,id'],
            'vehicle_id'        => ['nullable', 'exists:vehicles,id'],
            'transportir_name'  => ['nullable', 'string', 'max:200'],
            'container_number'  => ['nullable', 'string', 'max:100'],
            'eta'               => ['nullable', 'date'],
            'notes'             => ['nullable', 'string'],
        ]);

        $data['document_type']     = 'po';
        $data['counterparty_type'] = 'branch';
        $data['counterparty_name'] = $data['counterparty_name'] ?? $mainBranch?->name;
        $data['origin_branch_id']  = $mainBranch?->id;
        $data['destination_branch_id'] = $user->branch_id;
        $data['requested_by']      = $user->id;
        $data['status']            = 'draft';

        $po = DeliveryOrder::create($data);

        return $this->created(
            new DeliveryOrderResource($po->load('originBranch', 'destinationBranch', 'expedition', 'vehicle'))
        );
    }

    /**
     * Update a draft Purchase Order.
     */
    public function update(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($deliveryOrder->document_type !== 'po') {
            return $this->error('Not a Purchase Order.', 422);
        }
        if ($deliveryOrder->status !== 'draft') {
            return $this->error('Only draft orders can be edited.', 422);
        }

        $data = $request->validate([
            'cylinder_type'     => ['sometimes', 'in:3kg,5.5kg,12kg,50kg'],
            'quantity_ordered'  => ['sometimes', 'integer', 'min:1'],
            'counterparty_name' => ['nullable', 'string', 'max:200'],
            'expedition_id'     => ['nullable', 'exists:expeditions,id'],
            'vehicle_id'        => ['nullable', 'exists:vehicles,id'],
            'transportir_name'  => ['nullable', 'string', 'max:200'],
            'container_number'  => ['nullable', 'string', 'max:100'],
            'eta'               => ['nullable', 'date'],
            'notes'             => ['nullable', 'string'],
        ]);

        $deliveryOrder->update($data);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()->load('originBranch', 'destinationBranch', 'expedition', 'vehicle')));
    }

    /**
     * Submit a Purchase Order for approval.
     */
    public function submit(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        if ($deliveryOrder->document_type !== 'po') {
            return $this->error('Not a Purchase Order.', 422);
        }
        if ($deliveryOrder->status !== 'draft') {
            return $this->error('Only draft orders can be submitted.', 422);
        }

        $deliveryOrder->update(['status' => 'pending_approval']);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()), 'Submitted for approval.');
    }

    /**
     * Approve a Purchase Order.
     */
    public function approve(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->canApproveOrders()) {
            return $this->forbidden();
        }
        if ($deliveryOrder->document_type !== 'po') {
            return $this->error('Not a Purchase Order.', 422);
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

    /**
     * Mark a Purchase Order as in transit.
     */
    public function markInTransit(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->canApproveOrders()) {
            return $this->forbidden();
        }
        if ($deliveryOrder->document_type !== 'po') {
            return $this->error('Not a Purchase Order.', 422);
        }
        if ($deliveryOrder->status !== 'approved') {
            return $this->error('Only approved orders can be marked in transit.', 422);
        }

        $deliveryOrder->update(['status' => 'in_transit']);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()), 'Marked in transit.');
    }

    /**
     * Receive a Purchase Order (mark delivered).
     */
    public function receive(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        if ($deliveryOrder->document_type !== 'po') {
            return $this->error('Not a Purchase Order.', 422);
        }
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

    /**
     * Cancel a Purchase Order.
     */
    public function cancel(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->canApproveOrders()) {
            return $this->forbidden();
        }
        if ($deliveryOrder->document_type !== 'po') {
            return $this->error('Not a Purchase Order.', 422);
        }
        if (! in_array($deliveryOrder->status, ['draft', 'pending_approval'])) {
            return $this->error('Only draft or pending orders can be cancelled.', 422);
        }

        $deliveryOrder->update(['status' => 'cancelled']);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()), 'Cancelled.');
    }

    /**
     * Delete a draft Purchase Order.
     */
    public function destroy(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($deliveryOrder->document_type !== 'po') {
            return $this->error('Not a Purchase Order.', 422);
        }
        if ($deliveryOrder->status !== 'draft') {
            return $this->error('Only draft orders can be deleted.', 422);
        }

        $deliveryOrder->delete();

        return $this->noContent();
    }

    private function authorizeView($user, DeliveryOrder $do): void
    {
        if ($user->isOwnerPusat() || $user->isRegionalLeader()) return;
        if ($do->origin_branch_id === $user->branch_id) return;
        if ($do->destination_branch_id === $user->branch_id) return;
        abort(403);
    }
}
