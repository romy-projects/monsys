<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryOrderResource;
use App\Http\Traits\ApiResponse;
use App\Models\DeliveryOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LEGACY, read-only (N-GEN-7, 2026-11-20).
 *
 * The DO flow is retired: PO (branch → HQ) and LO (HQ → transportir) are the live
 * chain and proof of delivery lives on the PO (D10). Only index/show remain so
 * historical documents stay readable — mobilex still opens GET /delivery-orders.
 * Phase 15 (T15-01) deleted the unreachable write/workflow/receipt methods.
 */
class DeliveryOrderController extends Controller
{
    use ApiResponse;

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

    private function authorizeView($user, DeliveryOrder $do): void
    {
        if ($user->isOwnerPusat() || $user->isRegionalLeader()) return;
        if ($do->origin_branch_id === $user->branch_id) return;
        if ($do->destination_branch_id === $user->branch_id) return;
        abort(403);
    }
}
