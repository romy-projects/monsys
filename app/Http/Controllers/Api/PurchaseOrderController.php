<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryOrderResource;
use App\Http\Traits\ApiResponse;
use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\Role;
use App\Models\StockClose;
use App\Services\DocumentNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DocumentNumberService $documentNumbers) {}

    /** List Purchase Orders requesting goods from HQ for a branch. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->hasPermission('purchase_orders.view')) {
            return $this->forbidden();
        }

        $query = DeliveryOrder::purchaseOrders()
            ->accessibleTo($user)
            ->with(['originBranch', 'destinationBranch', 'transportir', 'expedition', 'vehicle', 'requestedBy']);

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
            new DeliveryOrderResource($deliveryOrder->load('originBranch', 'destinationBranch', 'transportir', 'expedition', 'vehicle', 'requestedBy', 'approvedBy'))
        );
    }

    /** Create a Purchase Order from HQ to its selected requesting branch. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('purchase_orders.create')) {
            return $this->forbidden('You cannot create Purchase Orders.');
        }

        $mainBranch = Branch::mainBranch()->first();

        if (! $mainBranch) {
            return $this->error('The HQ branch is not configured.', 422);
        }

        if ($user->accessRole?->data_scope === Role::SCOPE_OWN_BRANCH) {
            $request->merge(['destination_branch_id' => $user->branch_id]);
        }

        $destinationRules = [
            'integer',
            Rule::exists('branches', 'id')->where('status', 'active'),
            Rule::notIn([$mainBranch->id]),
        ];
        $data = $request->validate([
            'do_number' => ['nullable', 'string', 'max:50', 'unique:delivery_orders,do_number'],
            'order_date' => ['required', 'date'],
            'cylinder_type' => ['required', 'in:3kg,5.5kg,12kg,50kg'],
            'quantity_ordered' => ['required', 'integer', 'min:1'],
            'destination_branch_id' => $user->canAccessAllBranches()
                ? array_merge(['required'], $destinationRules)
                : array_merge(['sometimes', 'nullable'], $destinationRules),
            'counterparty_name' => ['nullable', 'string', 'max:200'],
            'transportir_id' => ['nullable', 'exists:transportirs,id'],
            'expedition_id' => ['nullable', 'exists:expeditions,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'transportir_name' => ['nullable', 'string', 'max:200'],
            'container_number' => ['nullable', 'string', 'max:100'],
            'eta' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $destinationBranchId = (int) ($data['destination_branch_id'] ?? $user->branch_id ?? 0);
        if (! $user->canAccessBranch($destinationBranchId)) {
            return $this->forbidden();
        }

        if (! $user->canAccessAllBranches() && ! StockClose::isTodaySubmitted($destinationBranchId)) {
            return $this->error("Today's stock close must be submitted before creating a Purchase Order.", 422);
        }

        $data['document_type'] = 'po';
        $data['counterparty_type'] = 'branch';
        $data['counterparty_name'] = $mainBranch->name;
        $data['origin_branch_id'] = $mainBranch?->id;
        $data['destination_branch_id'] = $destinationBranchId;
        $data['requested_by'] = $user->id;
        $data['status'] = 'draft';

        // The client sends business data only; allocate the PO number server-side when it is
        // omitted. Allocation and insert share one transaction so concurrent creates queue.
        $po = DB::transaction(function () use ($data) {
            $data['do_number'] ??= $this->documentNumbers->next('po');

            return DeliveryOrder::create($data);
        });

        return $this->created(
            new DeliveryOrderResource($po->load('originBranch', 'destinationBranch', 'transportir', 'expedition', 'vehicle', 'requestedBy'))
        );
    }

    /**
     * Update a draft Purchase Order.
     */
    public function update(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->canManage($user, $deliveryOrder, 'purchase_orders.update')) {
            return $this->forbidden();
        }
        if ($deliveryOrder->document_type !== 'po') {
            return $this->error('Not a Purchase Order.', 422);
        }
        if ($deliveryOrder->status !== 'draft') {
            return $this->error('Only draft orders can be edited.', 422);
        }

        $data = $request->validate([
            'cylinder_type' => ['sometimes', 'in:3kg,5.5kg,12kg,50kg'],
            'quantity_ordered' => ['sometimes', 'integer', 'min:1'],
            'transportir_id' => ['nullable', 'exists:transportirs,id'],
            'expedition_id' => ['nullable', 'exists:expeditions,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'],
            'transportir_name' => ['nullable', 'string', 'max:200'],
            'container_number' => ['nullable', 'string', 'max:100'],
            'eta' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $deliveryOrder->update($data);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()->load('originBranch', 'destinationBranch', 'transportir', 'expedition', 'vehicle')));
    }

    /**
     * Submit a Purchase Order for approval.
     */
    public function submit(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        if (! $this->canManage($request->user(), $deliveryOrder, 'purchase_orders.submit')) {
            return $this->forbidden();
        }
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

        if (! $user->hasPermission('purchase_orders.approve')
            || ! $user->canAccessModelRecord($deliveryOrder, 'update')) {
            return $this->forbidden();
        }
        if ($deliveryOrder->document_type !== 'po') {
            return $this->error('Not a Purchase Order.', 422);
        }
        if ($deliveryOrder->status !== 'pending_approval') {
            return $this->error('Only pending orders can be approved.', 422);
        }

        $deliveryOrder->update([
            'status' => 'approved',
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

        if (! $user->hasPermission('purchase_orders.mark_in_transit')
            || ! $user->canAccessModelRecord($deliveryOrder, 'update')) {
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
        $user = $request->user();

        if (! $user->hasPermission('purchase_orders.receive')
            || ! $user->canAccessBranch((int) $deliveryOrder->destination_branch_id)) {
            return $this->forbidden();
        }
        if ($deliveryOrder->document_type !== 'po') {
            return $this->error('Not a Purchase Order.', 422);
        }
        if ($deliveryOrder->status !== 'in_transit') {
            return $this->error('Only in-transit orders can be received.', 422);
        }

        $data = $request->validate([
            'quantity_received' => ['required', 'integer', 'min:0'],
            'received_date' => ['required', 'date'],
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

        if (! $user->hasPermission('purchase_orders.cancel')
            || ! $user->canAccessModelRecord($deliveryOrder, 'update')) {
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

        if (! $this->canManage($user, $deliveryOrder, 'purchase_orders.delete')) {
            return $this->forbidden();
        }
        if ($deliveryOrder->document_type !== 'po') {
            return $this->error('Not a Purchase Order.', 422);
        }
        if ($deliveryOrder->status !== 'draft') {
            return $this->error('Only draft orders can be deleted.', 422);
        }

        $deliveryOrder->delete();

        return $this->noContent();
    }

    /**
     * D10 (2026-11-20): upload the proof of delivery on the PO. The DO receipt route
     * was retired with N-GEN-7; the guard stays the same — approvers OR the receiving
     * (destination) branch, because proof-of-delivery is a receiving-side artifact.
     */
    public function uploadReceipt(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('purchase_orders.upload_receipt')
            || ! $user->canAccessBranch((int) $deliveryOrder->destination_branch_id)) {
            return $this->forbidden('Only HQ or the destination branch can upload this receipt.');
        }

        if ($deliveryOrder->document_type !== 'po') {
            return $this->error('Not a Purchase Order.', 422);
        }

        if (! in_array($deliveryOrder->status, ['in_transit', 'delivered'], true)) {
            return $this->error('A receipt can only be uploaded for an in-transit or delivered Purchase Order.', 422);
        }

        $request->validate([
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ]);

        $path = $request->file('receipt')->store('po-receipts', 'public');

        $deliveryOrder->update(['receipt_path' => $path]);

        return $this->success(
            new DeliveryOrderResource($deliveryOrder->fresh()),
            'Receipt uploaded.'
        );
    }

    private function canManage($user, DeliveryOrder $po, string $permission): bool
    {
        return $po->document_type === 'po'
            && $user->hasPermission($permission)
            && $user->canAccessBranch((int) $po->destination_branch_id);
    }

    private function authorizeView($user, DeliveryOrder $po): void
    {
        if ($po->document_type === 'po'
            && $user->hasPermission('purchase_orders.view')
            && $user->canAccessModelRecord($po, 'view')) {
            return;
        }

        abort(403);
    }
}
