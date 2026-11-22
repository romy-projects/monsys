<?php

namespace App\Support;

use App\Models\StockMutation;

/**
 * Phase 19B / T19B-14: the quantity side of the stock accounts.
 *
 * The stock accounts (`1-131` / `1-132` / `1-133`) are only as good as the
 * quantity they represent, so the stock-opname posting needs one definition of
 * "how many cylinders should the branch hold on that date". This class is that
 * definition, taken from the rules `StockSummaryReport` already uses:
 *
 *  - `in`         adds the quantity to the branch;
 *  - `out`        removes it;
 *  - `transfer`   removes it from `source_branch_id` and adds it to
 *                 `destination_branch_id`;
 *  - `adjustment` is *not* a movement: the stock summary treats it as the
 *                 physical recount that overrides the ledger, and the opname
 *                 posting itself is what books that recount.
 *
 * Limitation (decision P4, 2026-11-23): sales never write a `stock_mutations`
 * row, so a branch ledger only carries receipts and transfers. The difference
 * of a branch close therefore also contains the cylinders that left through
 * sales; that is why the opname entry is the *only* place the ledger learns
 * about them and why the figure is worth reviewing before it is posted.
 */
class StockLedger
{
    /** Quantity of full cylinders the mutation ledger expects on a date. */
    public static function quantityOn(int $branchId, string $cylinderType, string $date): int
    {
        $mutations = StockMutation::query()
            ->where('cylinder_type', $cylinderType)
            ->whereDate('mutation_date', '<=', $date)
            ->where(function ($query) use ($branchId): void {
                $query->where('branch_id', $branchId)
                    ->orWhere('source_branch_id', $branchId)
                    ->orWhere('destination_branch_id', $branchId);
            })
            ->get(['branch_id', 'source_branch_id', 'destination_branch_id', 'mutation_type', 'quantity']);

        $quantity = 0;

        foreach ($mutations as $mutation) {
            $amount = (int) $mutation->quantity;

            switch ($mutation->mutation_type) {
                case 'in':
                    $quantity += (int) $mutation->branch_id === $branchId ? $amount : 0;
                    break;
                case 'out':
                    $quantity -= (int) $mutation->branch_id === $branchId ? $amount : 0;
                    break;
                case 'transfer':
                    if ((int) $mutation->source_branch_id === $branchId) {
                        $quantity -= $amount;
                    }

                    if ((int) $mutation->destination_branch_id === $branchId) {
                        $quantity += $amount;
                    }

                    break;
                default:
                    // `adjustment`: a recount, posted by the opname entry itself.
                    break;
            }
        }

        return $quantity;
    }
}
