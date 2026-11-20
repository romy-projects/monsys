<?php

namespace App\Services;

use App\Models\CylinderReconciliation;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * T12-08b (2026-11-20): a delivered LO that leaves a quantity discrepancy must be
 * visible to both sides of the claim — HQ and the branch that owns the PO.
 *
 * Recipients are deliberately narrow:
 *   - HQ: active `owner_pusat` users;
 *   - branch: active `owner_cabang` / `staff_gudang` of the reconciliation's own
 *     branch only (their own numbers, never another branch's).
 *
 * Regional leaders are not recipients (D8 makes Rekonsiliasi Tabung pusat-only),
 * and a balanced reconciliation notifies nobody.
 */
class ReconciliationNotifier
{
    public function notifyDiscrepancy(CylinderReconciliation $reconciliation): void
    {
        if ($reconciliation->case_type === 'balanced') {
            return;
        }

        $recipients = $this->recipients($reconciliation);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title("⚠️ Rekonsiliasi Tabung — {$reconciliation->case_label}")
            ->body($this->body($reconciliation))
            ->warning()
            ->icon('heroicon-o-exclamation-triangle')
            ->sendToDatabase($recipients);
    }

    /**
     * HQ plus the employees of the branch that owns the PO.
     *
     * @return Collection<int, User>
     */
    public function recipients(CylinderReconciliation $reconciliation): Collection
    {
        $hq = User::query()
            ->where('role', 'owner_pusat')
            ->where('status', 'active')
            ->get();

        $branch = User::query()
            ->where('branch_id', $reconciliation->branch_id)
            ->whereIn('role', ['owner_cabang', 'staff_gudang'])
            ->where('status', 'active')
            ->get();

        return $hq->concat($branch)->unique('id')->values();
    }

    /** Short, factual body: the chain, the branch, the quantities and the open value. */
    private function body(CylinderReconciliation $reconciliation): string
    {
        $detail = collect([
            (int) $reconciliation->quantity_unredeemed > 0
                ? $reconciliation->quantity_unredeemed . ' unit unredeemed'
                : null,
            (int) $reconciliation->quantity_short_delivered > 0
                ? $reconciliation->quantity_short_delivered . ' unit short delivery'
                : null,
            (int) $reconciliation->quantity_over_delivered > 0
                ? $reconciliation->quantity_over_delivered . ' unit over delivery'
                : null,
        ])->filter()->implode(', ');

        $po = $reconciliation->purchaseOrder?->do_number ?? '—';
        $so = $reconciliation->salesOrder?->do_number;
        $lo = $reconciliation->loadingOrder?->do_number ?? '—';
        $branch = $reconciliation->branch?->name ?? 'Branch';

        $chain = $so
            ? "PO #{$po} → SO #{$so} → LO #{$lo}"
            : "PO #{$po} → LO #{$lo}";

        return "{$chain} ({$reconciliation->cylinder_type}) at {$branch}: {$detail}. "
            . 'Open value: Rp ' . number_format($reconciliation->openSettlementAmount(), 0, ',', '.') . '. '
            . 'Settle through the next Invoice/SO/LOT/LOE.';
    }
}
