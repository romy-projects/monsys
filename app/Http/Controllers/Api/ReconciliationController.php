<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CylinderReconciliationResource;
use App\Http\Resources\ReconciliationSettlementResource;
use App\Http\Traits\ApiResponse;
use App\Models\CylinderReconciliation;
use App\Models\DeliveryOrder;
use App\Models\Invoice;
use App\Models\ReconciliationSettlement;
use App\Models\User;
use App\Services\CylinderReconciliationService;
use App\Support\OutstandingReconciliations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * BALI-18…22: read the reconciliations produced when an LO is delivered, and
 * trigger one manually ("Rekonsiliasi" action) without waiting for the observer.
 *
 * Access is granted by the database-managed reconciliation permissions and branch scope.
 */
class ReconciliationController extends Controller
{
    use ApiResponse;

    private const RELATIONS = [
        'purchaseOrder',
        'loadingOrder',
        'salesOrder',
        'branch',
        'settlements',
        'createdBy',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->canView($user)) {
            return $this->forbidden();
        }

        $query = CylinderReconciliation::query()->with(self::RELATIONS);

        if ($request->filled('branch_id')) {
            if (! $user->canAccessBranch((int) $request->branch_id)) {
                return $this->forbidden();
            }

            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('case_type')) {
            $query->where('case_type', $request->case_type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        // D6: reconciliations that still have (or already had) an obligation in this state.
        if ($request->filled('settlement_status')) {
            $query->whereHas('settlements', fn ($settlements) => $settlements->where('status', $request->settlement_status));
        }
        // T13-05: look a reconciliation up by any number of its chain (partial match).
        foreach (['po_number' => 'purchaseOrder', 'so_number' => 'salesOrder', 'lo_number' => 'loadingOrder'] as $parameter => $relation) {
            if (! $request->filled($parameter)) {
                continue;
            }

            $number = trim((string) $request->input($parameter));

            $query->whereHas(
                $relation,
                fn ($document) => $document->where('do_number', 'like', "%{$number}%")
            );
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }
        if ($request->filled('until')) {
            $query->whereDate('created_at', '<=', $request->until);
        }

        $paginator = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(30);

        return $this->paginated(
            $paginator,
            fn (CylinderReconciliation $row) => (new CylinderReconciliationResource($row))->resolve()
        );
    }

    public function show(CylinderReconciliation $reconciliation, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->canView($user)) {
            return $this->forbidden();
        }

        return $this->success(
            new CylinderReconciliationResource($reconciliation->load(self::RELATIONS))
        );
    }

    /**
     * Manual "Rekonsiliasi" trigger. Safe to call repeatedly: the service is
     * idempotent (BALI-22).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('reconciliations.reconcile')) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'loading_order_id' => [
                'required',
                'integer',
                Rule::exists('delivery_orders', 'id')
                    ->where('document_type', 'lo')
                    ->where('status', 'delivered')
                    ->whereNull('deleted_at'),
            ],
        ]);

        $lo = DeliveryOrder::loadingOrders()
            ->accessibleTo($user)
            ->findOrFail($data['loading_order_id']);

        $reconciliation = app(CylinderReconciliationService::class)->reconcile($lo, $user->id);

        if (! $reconciliation) {
            return $this->error(
                'This Loading Order cannot be reconciled: it has no PO reference or no destination branch.',
                422
            );
        }

        return $this->created(
            new CylinderReconciliationResource($reconciliation->load(self::RELATIONS))
        );
    }

    /**
     * D6/D7: the open credits/claims of a branch, for the data-entry warning and the
     * `notes` reference of the next Invoice / SO / LOT / LOE. Display only — nothing
     * is deducted automatically.
     */
    public function outstanding(Request $request): JsonResponse
    {
        if (! $this->canView($request->user())) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')],
            'cylinder_type' => ['nullable', Rule::in(['3kg', '5.5kg', '12kg', '50kg'])],
        ]);

        $branchId = (int) $data['branch_id'];
        if (! $request->user()->canAccessBranch($branchId)) {
            return $this->forbidden();
        }

        $cylinderType = $data['cylinder_type'] ?? null;

        $rows = OutstandingReconciliations::forBranch($branchId, $cylinderType);

        return $this->success([
            'branch_id' => $branchId,
            'cylinder_type' => $cylinderType,
            'note_reference' => OutstandingReconciliations::noteReference($branchId, $cylinderType),
            'total_amount' => (float) $rows->sum(fn (ReconciliationSettlement $row) => (float) $row->amount),
            'rows' => $rows->map(fn (ReconciliationSettlement $row) => [
                'id' => $row->id,
                'reconciliation_id' => $row->reconciliation_id,
                'party' => $row->party,
                'settlement_type' => $row->settlement_type,
                'type_label' => $row->type_label,
                'quantity' => (int) $row->quantity,
                'unit_price' => (float) $row->unit_price,
                'amount' => (float) $row->amount,
                'status' => $row->status,
                'applies_to_next_delivery' => (bool) $row->applies_to_next_delivery,
                'source_purchase_order_id' => $row->source_purchase_order_id,
                'purchase_order_number' => $row->reconciliation?->purchaseOrder?->do_number,
                'loading_order_number' => $row->reconciliation?->loadingOrder?->do_number,
                'cylinder_type' => $row->reconciliation?->cylinder_type,
                'line' => OutstandingReconciliations::line($row),
            ])->values()->all(),
        ], 'Outstanding reconciliation obligations.');
    }

    /**
     * D6: settle one obligation with the document that carried it (Invoice / SO /
     * LOT / LOE). Receivables and payables are never touched from here.
     */
    public function settle(ReconciliationSettlement $settlement, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('reconciliations.settle')
            || ! $user->canAccessBranch((int) $settlement->reconciliation?->branch_id)) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'settled_by_type' => ['required', Rule::in(array_keys(ReconciliationSettlement::documents()))],
            'document_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $this->settlementDocumentExists($data['settled_by_type'], (int) $data['document_id'], $user)) {
            return $this->error('The settlement document does not exist or is outside your branch scope.', 422);
        }

        if (! $settlement->markSettled($data['settled_by_type'], (int) $data['document_id'], $user->id)) {
            return $this->error('This settlement is not open anymore.', 422);
        }

        if (filled($data['notes'] ?? null)) {
            $settlement->forceFill([
                'notes' => trim(((string) $settlement->notes)."\n".trim($data['notes'])),
            ])->save();
        }

        return $this->success(
            new ReconciliationSettlementResource($settlement->fresh()),
            'Settlement marked as settled.'
        );
    }

    /** Void an obligation the admin decided not to charge (history is kept). */
    public function void(ReconciliationSettlement $settlement, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPermission('reconciliations.void')
            || ! $user->canAccessBranch((int) $settlement->reconciliation?->branch_id)) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $settlement->markVoid($user->id, $data['reason'] ?? null)) {
            return $this->error('This settlement is already void.', 422);
        }

        return $this->success(
            new ReconciliationSettlementResource($settlement->fresh()),
            'Settlement voided.'
        );
    }

    /** The next document that settles an obligation must exist and match its type. */
    private function settlementDocumentExists(string $type, int $id, User $user): bool
    {
        return match ($type) {
            ReconciliationSettlement::DOCUMENT_INVOICE => Invoice::query()
                ->whereKey($id)
                ->whereHas('branch', fn ($query) => $query->whereIn('branches.id', $user->accessibleBranchIds()))
                ->exists(),
            ReconciliationSettlement::DOCUMENT_SALES_ORDER => DeliveryOrder::salesOrders()->accessibleTo($user)->whereKey($id)->exists(),
            ReconciliationSettlement::DOCUMENT_LOADING_ORDER => DeliveryOrder::loadingOrders()->accessibleTo($user)->whereKey($id)->exists(),
            default => false,
        };
    }

    private function canView($user): bool
    {
        return $user !== null && $user->hasPermission('reconciliations.view');
    }
}
