<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CylinderReconciliationResource;
use App\Http\Traits\ApiResponse;
use App\Models\CylinderReconciliation;
use App\Models\DeliveryOrder;
use App\Services\CylinderReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * BALI-18…22: read the reconciliations produced when an LO is delivered, and
 * trigger one manually ("Rekonsiliasi" action) without waiting for the observer.
 *
 * RBAC follows the finance endpoints: pusat/regional see everything, branch users
 * see only their own branch; only pusat/regional may force a reprocess.
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

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('case_type')) {
            $query->where('case_type', $request->case_type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
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

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()
            && (int) $reconciliation->branch_id !== (int) $user->branch_id) {
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

        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            return $this->forbidden('Only Pusat/Regional can reconcile a Loading Order.');
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

        $lo = DeliveryOrder::findOrFail($data['loading_order_id']);

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

    private function canView($user): bool
    {
        return $user !== null
            && ($user->canViewFinance() || $user->isOwnerCabang() || $user->isStaffGudang());
    }
}
