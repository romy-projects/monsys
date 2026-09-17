<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryOrderResource;
use App\Http\Traits\ApiResponse;
use App\Models\DeliveryOrder;
use App\Models\StockClose;
use App\Services\DocumentNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeliveryOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DocumentNumberService $documentNumbers)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Only Pusat/Regional can access DOs — branches use Purchase Orders (PO) instead
        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            return $this->forbidden('Only Pusat/Regional can access Delivery Orders. Other branches use Purchase Orders.');
        }

        $query = DeliveryOrder::query()
            ->with(['originBranch', 'destinationBranch', 'transportir', 'expedition', 'vehicle']);

        // Default: only show actual DOs (not SO/LO/PO) unless document_type is specified
        if ($request->filled('document_type')) {
            $query->where('document_type', $request->document_type);
        } else {
            $query->where('document_type', 'do');
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
            $query->where(
                fn($q) => $q
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
            new DeliveryOrderResource($deliveryOrder->load('originBranch', 'destinationBranch', 'transportir', 'expedition', 'vehicle', 'requestedBy', 'approvedBy'))
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
            'do_number'              => ['nullable', 'string', 'max:50', 'unique:delivery_orders,do_number'],
            'order_date'             => ['required', 'date'],
            'cylinder_type'          => ['required', 'in:3kg,5.5kg,12kg,50kg'],
            'quantity_ordered'       => ['required', 'integer', 'min:1'],
            'destination_branch_id'  => ['required', 'exists:branches,id'],
            'supplier_name'          => ['nullable', 'string', 'max:200'],
            'transportir_id'         => ['nullable', 'exists:transportirs,id'],
            'expedition_id'          => ['nullable', 'exists:expeditions,id'],
            'vehicle_id'             => ['nullable', 'exists:vehicles,id'],
            'transportir_name'       => ['nullable', 'string', 'max:200'],
            'container_number'       => ['nullable', 'string', 'max:100'],
            'counterparty_type'      => ['nullable', 'in:pertamina,branch'],
            'counterparty_name'      => ['nullable', 'string', 'max:200'],
            'so_number'              => ['nullable', 'string', 'max:100'],
            'po_number'              => ['nullable', 'string', 'max:100'],
            'eta'                    => ['nullable', 'date'],
            'notes'                  => ['nullable', 'string'],
        ]);

        $data['requested_by'] = $user->id;
        $data['status']       = 'draft';
        $data['document_type'] = 'do';

        // Only Pusat/Regional users can set origin_branch_id; otherwise always Pusat
        if ($data['order_type'] === 'supplier') {
            $data['origin_branch_id'] = null;
        } elseif ($user->isOwnerPusat() || $user->isRegionalLeader()) {
            $data['origin_branch_id'] = $request->input('origin_branch_id', 1);
        } else {
            // Branch users can only request stock from Pusat
            $data['origin_branch_id'] = 1;
        }

        // Allocate the DO number server-side when the client omits it (allocation + insert
        // in one transaction so concurrent creates queue instead of colliding).
        $do = DB::transaction(function () use ($data) {
            $data['do_number'] ??= $this->documentNumbers->next('do');

            return DeliveryOrder::create($data);
        });

        return $this->created(
            new DeliveryOrderResource($do->load('originBranch', 'destinationBranch', 'transportir', 'expedition', 'vehicle'))
        );
    }
    /**
     * Upload the proof-of-delivery receipt for an order on transportir or already delivered.
     *
     * Authorized for approvers (Pusat/Regional) and for the destination branch that physically
     * received the Tabung — the branch is the party that holds the signed receipt.
     */
    public function uploadReceipt(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->canApproveOrders() && $user->branch_id !== $deliveryOrder->destination_branch_id) {
            return $this->forbidden('Only approvers or the destination branch can upload a receipt.');
        }

        if (! in_array($deliveryOrder->status, ['on_transportir', 'delivered'])) {
            return $this->error('A receipt can only be uploaded for orders on transportir or delivered.', 422);
        }

        $request->validate([
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:2048'],
        ]);

        $path = $request->file('receipt')->store('do-receipts', 'public');

        $deliveryOrder->update(['receipt_path' => $path]);

        return $this->success(
            new DeliveryOrderResource($deliveryOrder->fresh()),
            'Receipt uploaded.'
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
            'supplier_name'         => ['nullable', 'string', 'max:200'],
            'transportir_id'        => ['nullable', 'exists:transportirs,id'],
            'expedition_id'         => ['nullable', 'exists:expeditions,id'],
            'vehicle_id'            => ['nullable', 'exists:vehicles,id'],
            'transportir_name'      => ['nullable', 'string', 'max:200'],
            'container_number'      => ['nullable', 'string', 'max:100'],
            'counterparty_type'     => ['nullable', 'in:pertamina,branch'],
            'counterparty_name'     => ['nullable', 'string', 'max:200'],
            'so_number'             => ['nullable', 'string', 'max:100'],
            'po_number'             => ['nullable', 'string', 'max:100'],
            'eta'                   => ['nullable', 'date'],
            'notes'                 => ['nullable', 'string'],
        ]);

        // Non-pusat users cannot change origin_branch_id
        if ($user->isOwnerPusat() || $user->isRegionalLeader()) {
            $data['origin_branch_id'] = $request->input('origin_branch_id', $deliveryOrder->origin_branch_id);
        }

        $deliveryOrder->update($data);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()->load('originBranch', 'destinationBranch', 'transportir', 'expedition', 'vehicle')));
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
