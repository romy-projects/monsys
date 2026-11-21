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
        if (! $user->hasPermission('legacy_delivery_orders.view') || ! $user->canAccessAllBranches()) {
            return $this->forbidden();
        }
        if ($request->filled('document_type') && $request->input('document_type') !== 'do') {
            return $this->error('This endpoint only exposes legacy Delivery Orders.', 422);
        }

        $query = DeliveryOrder::deliveryOrders()
            ->accessibleTo($user)
            ->with(['originBranch', 'destinationBranch', 'transportir', 'expedition', 'vehicle']);

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
            if (! $user->canAccessBranch((int) $request->branch_id)) {
                return $this->forbidden();
            }

            $query->where(function ($q) use ($request): void {
                $q->where('origin_branch_id', $request->branch_id)
                    ->orWhere('destination_branch_id', $request->branch_id);
            });
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
        abort_unless(
            $do->document_type === 'do' &&
                $user->hasPermission('legacy_delivery_orders.view') &&
                $user->canAccessModelRecord($do, 'view'),
            403
        );
    }
}
