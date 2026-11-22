<?php

namespace App\Support;

use App\Models\DeliveryOrder;
use App\Models\Invoice;

/**
 * Phase 19A / T19A-08: one sales-detail dataset shared by the panel page and the
 * Excel export, so the screen and the file can never disagree.
 *
 * Columns mirror the client's SALES REPORT sheet: invoice + pangkalan + branch,
 * cylinder quantity, price, total, the delivery fee, and the payment details
 * that used to be lost with the old `paid_amount +=` action.
 */
class SalesDetailReport
{
    /**
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, float>}
     */
    public static function build(?int $branchId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $startDate = $startDate ?: now()->startOfMonth()->toDateString();
        $endDate = $endDate ?: now()->toDateString();

        $invoices = Invoice::query()
            ->with(['branch:id,name', 'payments.payment:id,paid_on,method,reference_no,cash_account_id'])
            ->whereDate('issue_date', '>=', $startDate)
            ->whereDate('issue_date', '<=', $endDate)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get();

        // Pertamina's own reference lives on the loading order, not the invoice.
        $references = DeliveryOrder::query()
            ->whereIn('do_number', $invoices->pluck('loading_order_number')->filter()->unique()->all())
            ->pluck('pertamina_reference', 'do_number')
            ->all();

        $rows = [];
        $totals = [
            'quantity' => 0.0,
            'product' => 0.0,
            'expedition_fee' => 0.0,
            'total' => 0.0,
            'paid' => 0.0,
            'balance' => 0.0,
        ];

        foreach ($invoices as $invoice) {
            $payments = $invoice->payments->sortBy(fn ($payment) => $payment->paid_on->toDateString().'-'.$payment->id);
            $paid = round((float) $payments->sum('amount'), 2);
            $total = round((float) $invoice->total_amount, 2);
            $last = $payments->last();

            $row = [
                'invoice_number' => (string) $invoice->invoice_number,
                'issue_date' => $invoice->issue_date->toDateString(),
                'redeemed_on' => $invoice->redeemed_on?->toDateString(),
                'buyer' => (string) ($invoice->reference_name ?? '-'),
                'branch' => (string) ($invoice->branch?->name ?? '-'),
                'cylinder_type' => (string) $invoice->cylinder_type,
                'quantity' => (float) $invoice->quantity,
                'unit_price' => (float) $invoice->unit_price,
                'product_total' => round((float) $invoice->unit_price * (float) $invoice->quantity, 2),
                'expedition_fee' => (float) $invoice->expedition_fee_amount,
                'total' => $total,
                'paid' => $paid,
                'balance' => max(0, round($total - $paid, 2)),
                'status' => (string) $invoice->status,
                'paid_on' => $last?->paid_on->toDateString(),
                'method' => $payments->pluck('method')->unique()->implode(', '),
                'reference_no' => $payments->pluck('reference_no')->filter()->unique()->implode(', '),
                'pertamina_reference' => $references[$invoice->loading_order_number] ?? null,
                'payment_count' => $payments->count(),
            ];

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (float) ($row[$key] ?? 0);
            }

            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'totals' => array_map(static fn (float $value): float => round($value, 2), $totals),
        ];
    }
}
