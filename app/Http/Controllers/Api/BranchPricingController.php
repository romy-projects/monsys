<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Support\BranchPricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BALI-14: preview the HPP / selling price for a branch after the Transportir
 * haulage rate and the Expedition delivery fee have been applied.
 *
 * Read-only — used by the finance screens and the mobile price checker.
 */
class BranchPricingController extends Controller
{
    use ApiResponse;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'branch_id'       => ['nullable', 'exists:branches,id'],
            'cylinder_type'   => ['required', 'in:3kg,5.5kg,12kg,50kg'],
            'quantity'        => ['nullable', 'integer', 'min:0'],
            'date'            => ['nullable', 'date'],
            'transportir_id'  => ['nullable', 'exists:transportirs,id'],
            'expedition_cost' => ['nullable', 'numeric', 'min:0'],
            'loading_order_number' => ['nullable', 'string', 'max:100'],
        ]);

        // Branch users only ever see their own branch's pricing.
        if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
            $data['branch_id'] = $user->branch_id;
        }

        if (filled($data['loading_order_number'] ?? null)) {
            $lo = \App\Models\DeliveryOrder::loadingOrders()
                ->where('do_number', $data['loading_order_number'])
                ->first();

            if ($lo) {
                return $this->success(BranchPricing::forLoadingOrder($lo, [
                    'charge_fee_to_buyer' => (bool) config('app.expedition_fee_charged_to_buyer', false),
                ]));
            }
        }

        return $this->success(BranchPricing::breakdown($data));
    }
}
