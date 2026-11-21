<?php

namespace App\Support;

use App\Models\DeliveryOrder;
use App\Models\LpgPrice;
use App\Models\Transportir;
use App\Models\TransportirRate;

/**
 * HPP / selling-price breakdown after Transportir and Expedition costs (BALI-14).
 *
 * Every number is a snapshot of the rates and prices effective on the given
 * date, so the caller can store them instead of re-deriving them later.
 *
 * Definitions
 * -----------
 *  gross_total        = selling_price × qty                (what the branch is billed for the gas)
 *  transportir_total  = transportir_rate × qty            (transportir haulage, per cylinder)
 *  delivery_fee       = flat expedition fee per LO        (never × qty)
 *  hpp_per_cylinder   = purchase_price + transportir_rate
 *  hpp_total          = hpp_per_cylinder × qty
 *  branch_total       = gross_total (+ delivery_fee when passed through to the buyer)
 *  net_after_costs    = branch_total − purchase_total − transportir_total − delivery_fee
 *
 * When the fee is passed through, it appears on both sides (revenue and cost), so
 * it nets out; when HQ absorbs it, it is a pure cost. That is why both cases use
 * the same formula.
 *
 * The meeting-note simulation (2.216.000 − 15.000 − 5.000 = 2.196.000) is this
 * same formula with purchase_total = 0 and fee passed through.
 */
class BranchPricing
{
    /**
     * @param  array{
     *     branch_id?: int|null,
     *     cylinder_type: string,
     *     quantity?: int,
     *     date?: string|null,
     *     transportir_id?: int|null,
     *     expedition_cost?: float|string|null,
     *     charge_fee_to_buyer?: bool|null
     * } $input
     * @return array<string, mixed>
     */
    public static function breakdown(array $input): array
    {
        $type = (string) $input['cylinder_type'];
        $quantity = max(0, (int) ($input['quantity'] ?? 0));
        $branchId = isset($input['branch_id']) ? (int) $input['branch_id'] : null;
        $date = ($input['date'] ?? null) ?: today()->toDateString();

        $transportirId = isset($input['transportir_id']) && $input['transportir_id']
            ? (int) $input['transportir_id']
            : null;

        $chargeFee = $input['charge_fee_to_buyer']
            ?? (bool) config('app.expedition_fee_charged_to_buyer', false);

        $price = LpgPrice::priceAtDate($type, $date, $branchId);
        $rate = $transportirId ? TransportirRate::rateAtDate($transportirId, $type, $date) : null;

        $purchasePrice = (float) ($price?->purchase_price ?? 0);
        $sellingPrice = (float) ($price?->selling_price ?? 0);
        $ratePerUnit = (float) ($rate?->rate ?? 0);
        $deliveryFee = (float) ($input['expedition_cost'] ?? 0);

        $grossTotal = $sellingPrice * $quantity;
        $purchaseTotal = $purchasePrice * $quantity;
        $transportirTotal = $ratePerUnit * $quantity;
        $hppPerCylinder = $purchasePrice + $ratePerUnit;
        $hppTotal = $hppPerCylinder * $quantity;
        $branchTotal = $grossTotal + ($chargeFee ? $deliveryFee : 0.0);

        return [
            'branch_id' => $branchId,
            'cylinder_type' => $type,
            'quantity' => $quantity,
            'date' => $date,

            'lpg_price_id' => $price?->id,
            'price_found' => $price !== null,
            'purchase_price' => round($purchasePrice, 2),
            'selling_price' => round($sellingPrice, 2),

            'transportir_id' => $transportirId,
            'transportir_name' => $transportirId ? Transportir::find($transportirId)?->name : null,
            'transportir_rate_id' => $rate?->id,
            'transportir_rate' => round($ratePerUnit, 2),
            'transportir_total' => round($transportirTotal, 2),

            'delivery_fee' => round($deliveryFee, 2),
            'fee_charged_to_buyer' => $chargeFee,
            'delivery_fee_charged' => $chargeFee ? round($deliveryFee, 2) : 0.0,

            'gross_total' => round($grossTotal, 2),
            'branch_total' => round($branchTotal, 2),

            'hpp_per_cylinder' => round($hppPerCylinder, 2),
            'hpp_total' => round($hppTotal, 2),

            'net_after_costs' => round($branchTotal - $purchaseTotal - $transportirTotal - $deliveryFee, 2),
            'margin_per_cylinder' => round($sellingPrice - $hppPerCylinder, 2),
        ];
    }

    /**
     * Breakdown for a Loading Order: quantity, cylinder type, transportir and
     * expedition fee are taken from the LO itself.
     *
     * @return array<string, mixed>
     */
    public static function forLoadingOrder(DeliveryOrder $lo, array $overrides = []): array
    {
        return self::breakdown(array_merge([
            'branch_id' => $lo->destination_branch_id,
            'cylinder_type' => $lo->cylinder_type,
            'quantity' => (int) $lo->quantity_ordered,
            'date' => $lo->order_date?->toDateString(),
            'transportir_id' => $lo->transportir_id,
            // A Transportir LO never shares its expedition cost with HQ.
            'expedition_cost' => $lo->expeditionFee(),
        ], $overrides));
    }
}
