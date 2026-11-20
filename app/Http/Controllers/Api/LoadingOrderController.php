<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryOrderResource;
use App\Http\Traits\ApiResponse;
use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Services\DocumentNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LoadingOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DocumentNumberService $documentNumbers)
    {
    }

    /**
     * List Loading Orders (LO) — Main Branch loading instructions to Transportir.
     */
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = DeliveryOrder::loadingOrders()
            ->with(['originBranch', 'destinationBranch', 'transportir', 'expedition', 'vehicle', 'loadedBy']);

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where('origin_branch_id', $user->branch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('cylinder_type')) {
            $query->where('cylinder_type', $request->cylinder_type);
        }
        if ($request->filled('lo_kind')) {
            if ($request->lo_kind === 'expedition') {
                $query->where('lo_kind', 'expedition');
            } else {
                $query->where(fn ($q) => $q->where('lo_kind', 'transportir')->orWhereNull('lo_kind'));
            }
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
     * Show a single Loading Order.
     */
    public function show(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeView($user, $deliveryOrder);

        return $this->success(
            new DeliveryOrderResource($deliveryOrder->load('originBranch', 'destinationBranch', 'transportir', 'expedition', 'vehicle', 'loadedBy', 'requestedBy', 'approvedBy'))
        );
    }

    /**
     * Create a Loading Order (LO).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            return $this->forbidden('Only Pusat/Regional can create Loading Orders.');
        }

        $mainBranch = Branch::mainBranch()->first();

        $data = $request->validate([
            'lo_kind'            => ['nullable', 'in:transportir,expedition'],
            'do_number'          => ['nullable', 'string', 'max:50', 'unique:delivery_orders,do_number'],
            'order_date'         => ['required', 'date'],
            'cylinder_type'      => ['required', 'in:3kg,5.5kg,12kg,50kg'],
            'quantity_ordered'   => ['required', 'integer', 'min:1'],
            'destination_branch_id' => ['required', 'exists:branches,id'],
            'so_number'          => ['nullable', 'string', 'max:100'],
            'po_number'          => ['nullable', 'string', 'max:100'],
            'counterparty_name'  => ['nullable', 'string', 'max:200'],
            'transportir_id'     => ['nullable', 'exists:transportirs,id'],
            'expedition_id'      => ['nullable', 'exists:expeditions,id'],
            'vehicle_id'         => ['nullable', 'exists:vehicles,id'],
            'transportir_name'   => ['nullable', 'string', 'max:200'],
            'container_number'   => ['nullable', 'string', 'max:100'],
            'loading_date'       => ['nullable', 'date'],
            'eta'                => ['nullable', 'date'],
            'notes'              => ['nullable', 'string'],
            'expedition_notes'   => ['nullable', 'string', 'max:255'],
        ]);

        $kind = $data['lo_kind'] ?? 'transportir';

        if ($kind === 'expedition') {
            // LO Expedisi: HQ arranges the expedition, so the container, the parent
            // Transportir LO and the flat delivery fee are mandatory (BALI-12/13).
            $data += $request->validate([
                'transportir_lo_number' => ['required', 'string', 'max:100', Rule::exists('delivery_orders', 'do_number')
                    ->where('document_type', 'lo')
                    ->where('lo_kind', 'transportir')],
                'expedition_id'         => ['required', 'exists:expeditions,id'],
                'container_number'      => ['required', 'string', 'max:100'],
                'expedition_cost'       => ['required', 'numeric', 'min:0'],
            ]);
        } else {
            // LO Transportir: continues a PO. The transportir reports the
            // expedition/container later and bears its own cost (BALI-11/13).
            $data += $request->validate([
                'po_number'      => ['required', 'string', 'max:100'],
                'transportir_id' => ['required', 'exists:transportirs,id'],
            ]);
        }

        $data['document_type']     = 'lo';
        $data['lo_kind']           = $kind;
        $data['origin_branch_id']  = $mainBranch?->id;
        $data['requested_by']      = $user->id;
        $data['status']            = 'draft';

        if ($kind === 'expedition') {
            $data['counterparty_type'] = 'branch';
            $data['counterparty_name'] = $data['counterparty_name']
                ?? Branch::find($data['destination_branch_id'])?->name;
            $data['cost_borne_by']     = 'sum';
        } else {
            $data['counterparty_type']     = 'pertamina';
            $data['counterparty_name']     = $data['counterparty_name'] ?? 'Pertamina';
            // The transportir pays for its own expedition: no fee reaches HQ (BALI-13).
            $data['cost_borne_by']         = 'transporter';
            $data['expedition_cost']       = null;
            $data['transportir_lo_number'] = null;
        }

        // Allocate the LO number server-side when the client omits it (allocation + insert
        // in one transaction so concurrent creates queue instead of colliding).
        // Transportir and Expedition LOs use separate series: LOT… / LOE… (BALI-11).
        $lo = DB::transaction(function () use ($data, $kind) {
            $data['do_number'] ??= $this->documentNumbers->next($kind === 'expedition' ? 'lo_e' : 'lo_t');

            return DeliveryOrder::create($data);
        });

        return $this->created(
            new DeliveryOrderResource($lo->load('originBranch', 'destinationBranch', 'transportir', 'expedition', 'vehicle'))
        );
    }

    /**
     * Update a draft Loading Order.
     */
    public function update(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($deliveryOrder->document_type !== 'lo') {
            return $this->error('Not a Loading Order.', 422);
        }
        if ($deliveryOrder->status !== 'draft') {
            return $this->error('Only draft orders can be edited.', 422);
        }

        $data = $request->validate([
            'cylinder_type'     => ['sometimes', 'in:3kg,5.5kg,12kg,50kg'],
            'quantity_ordered'  => ['sometimes', 'integer', 'min:1'],
            'destination_branch_id' => ['sometimes', 'exists:branches,id'],
            'so_number'         => ['nullable', 'string', 'max:100'],
            'po_number'         => ['sometimes', 'nullable', 'string', 'max:100'],
            'transportir_lo_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'counterparty_name' => ['nullable', 'string', 'max:200'],
            'transportir_id'    => ['nullable', 'exists:transportirs,id'],
            'expedition_id'     => ['nullable', 'exists:expeditions,id'],
            'vehicle_id'        => ['nullable', 'exists:vehicles,id'],
            'transportir_name'  => ['nullable', 'string', 'max:200'],
            'container_number'  => ['nullable', 'string', 'max:100'],
            'expedition_cost'   => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'expedition_notes'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'loading_date'      => ['nullable', 'date'],
            'eta'               => ['nullable', 'date'],
            'notes'             => ['nullable', 'string'],
        ]);

        if ($deliveryOrder->isExpeditionLoadingOrder()) {
            if (array_key_exists('container_number', $data) && blank($data['container_number'])) {
                return $this->error('Container number is required on an Expedition LO.', 422);
            }

            // The expedition fee always belongs to HQ on an Expedition LO.
            $data['cost_borne_by'] = 'sum';
        } else {
            // A Transportir LO never carries a delivery fee (BALI-13).
            $data['cost_borne_by']         = 'transporter';
            $data['expedition_cost']       = null;
            $data['transportir_lo_number'] = null;
        }

        $deliveryOrder->update($data);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()->load('originBranch', 'destinationBranch', 'transportir', 'expedition', 'vehicle')));
    }

    /**
     * Submit a Loading Order for approval.
     */
    public function submit(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        if ($deliveryOrder->document_type !== 'lo') {
            return $this->error('Not a Loading Order.', 422);
        }
        if ($deliveryOrder->status !== 'draft') {
            return $this->error('Only draft orders can be submitted.', 422);
        }

        // BALI-12: an Expedition LO cannot be submitted without a container number.
        if ($deliveryOrder->isExpeditionLoadingOrder() && blank($deliveryOrder->container_number)) {
            return $this->error('Container number is required before submitting an Expedition LO.', 422);
        }

        if (blank($deliveryOrder->destination_branch_id)) {
            return $this->error('Destination branch is required before submitting a Loading Order.', 422);
        }

        $deliveryOrder->update(['status' => 'pending_approval']);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()), 'Submitted for approval.');
    }

    /**
     * Approve a Loading Order.
     */
    public function approve(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->canApproveOrders()) {
            return $this->forbidden();
        }
        if ($deliveryOrder->document_type !== 'lo') {
            return $this->error('Not a Loading Order.', 422);
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
     * Mark a Loading Order as loaded & in transit.
     */
    public function markLoaded(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->canApproveOrders()) {
            return $this->forbidden();
        }
        if ($deliveryOrder->document_type !== 'lo') {
            return $this->error('Not a Loading Order.', 422);
        }
        if ($deliveryOrder->status !== 'approved') {
            return $this->error('Only approved orders can be marked loaded.', 422);
        }

        $data = $request->validate([
            'loading_date' => ['required', 'date'],
        ]);

        $deliveryOrder->update([
            'status'       => 'in_transit',
            'loading_date' => $data['loading_date'],
            'loaded_by'    => $user->id,
        ]);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()), 'Marked as loaded & in transit.');
    }

    /**
     * Cancel a Loading Order.
     */
    public function cancel(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->canApproveOrders()) {
            return $this->forbidden();
        }
        if ($deliveryOrder->document_type !== 'lo') {
            return $this->error('Not a Loading Order.', 422);
        }
        if (! in_array($deliveryOrder->status, ['draft', 'pending_approval'])) {
            return $this->error('Only draft or pending orders can be cancelled.', 422);
        }

        $deliveryOrder->update(['status' => 'cancelled']);

        return $this->success(new DeliveryOrderResource($deliveryOrder->fresh()), 'Cancelled.');
    }

    /**
     * Delete a draft Loading Order.
     */
    public function destroy(DeliveryOrder $deliveryOrder, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            return $this->forbidden();
        }
        if ($deliveryOrder->document_type !== 'lo') {
            return $this->error('Not a Loading Order.', 422);
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
