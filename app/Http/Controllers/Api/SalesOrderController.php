<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryOrderResource;
use App\Http\Traits\ApiResponse;
use App\Models\Branch;
use App\Models\DeliveryOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesOrderController extends Controller
{
    use ApiResponse;

    /**
     * List Sales Orders (SO) — Main Branch orders to Pertamina.
     */
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = DeliveryOrder::salesOrders()
            ->with(['originBranch', 'destinationBranch', 'expedition', 'vehicle']);

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where('origin_branch_id', $user->branch_id);
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
     * Show a single Sales Order.
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
     * Create a Sales Order (SO) to Pertamina.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            return $this->forbidden('Only Pusat/Regional can create Sales Orders.');
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

        $data['document_type']     = 'so';
        $data['counterparty_type'] = 'pertamina';
        $data['counterparty_name'] = $data['counterparty_name'] ?? 'Pertamina';
        $data['origin_branch_id']  = $mainBranch?->id;
        $data['requested_by']      = $user->id;
        $data['status']            = 'draft';

        $so = DeliveryOrder::create($data);

        return $this->created(
            new DeliveryOrderResource($so->load('originBranch', 'destinationBranch', 'expedition', 'vehicle'))
        );
    }

    /**
     * Update a draft Sales Order.
     */
    public function update(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($deliveryOrder->document_type !== 'so') {
            return $this->error('Not a Sales Order.', 422);
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
     * Submit a Sales Order for approval.
     */
    public function submit(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        if ($deliveryOrder->document_type !== 'so') {
            return $this->error('Not a Sales Order.', 422);
        }
        if ($deliveryOrder->status !== 'draft') {
            return $this->error('Only draft orders can be submitted.', 422);
        }

        $deliveryOrder->update(['status' => 'pending_approval']);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()), 'Submitted for approval.');
    }

    /**
     * Approve a Sales Order.
     */
    public function approve(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->canApproveOrders()) {
            return $this->forbidden();
        }
        if ($deliveryOrder->document_type !== 'so') {
            return $this->error('Not a Sales Order.', 422);
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
     * Cancel a Sales Order.
     */
    public function cancel(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->canApproveOrders()) {
            return $this->forbidden();
        }
        if ($deliveryOrder->document_type !== 'so') {
            return $this->error('Not a Sales Order.', 422);
        }
        if (! in_array($deliveryOrder->status, ['draft', 'pending_approval'])) {
            return $this->error('Only draft or pending orders can be cancelled.', 422);
        }

        $deliveryOrder->update(['status' => 'cancelled']);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()), 'Cancelled.');
    }

    /**
     * Delete a draft Sales Order.
     */
    public function destroy(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            return $this->forbidden();
        }
        if ($deliveryOrder->document_type !== 'so') {
            return $this->error('Not a Sales Order.', 422);
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
        abort(403);
    }
}