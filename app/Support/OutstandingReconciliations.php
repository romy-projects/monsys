<?php

namespace App\Support;

use App\Models\ReconciliationSettlement;
use Illuminate\Support\Collection;

/**
 * D6/D7 (2026-11-20): the open reconciliation credits/claims of one branch.
 *
 * Read model only. The admin sees (and is warned about) what the branch still owes
 * or is owed while entering the next Invoice / SO / LOT / LOE, and copies the
 * reference into that document's `notes`. Nothing is deducted automatically — the
 * settlement itself is recorded manually through the Rekonsiliasi Tabung screen.
 */
class OutstandingReconciliations
{
    /** Parties whose obligation is settled by a branch document (transportir claims never are). */
    private const SETTLEABLE_PARTIES = [
        ReconciliationSettlement::PARTY_HQ,
        ReconciliationSettlement::PARTY_BRANCH,
    ];

    /**
     * Every open settlement of the branch, optionally narrowed to one cylinder type.
     *
     * @return Collection<int, ReconciliationSettlement>
     */
    public static function forBranch(?int $branchId, ?string $cylinderType = null): Collection
    {
        if (! $branchId) {
            return collect();
        }

        return ReconciliationSettlement::query()
            ->with([
                'reconciliation.purchaseOrder',
                'reconciliation.salesOrder',
                'reconciliation.loadingOrder',
                'sourcePurchaseOrder',
            ])
            ->where('status', ReconciliationSettlement::STATUS_OPEN)
            ->whereHas('reconciliation', function ($query) use ($branchId, $cylinderType): void {
                $query->where('branch_id', $branchId);

                if (filled($cylinderType)) {
                    $query->where('cylinder_type', $cylinderType);
                }
            })
            ->orderBy('reconciliation_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * Only the rows a branch document can settle (HQ credits + branch claims).
     *
     * @return Collection<int, ReconciliationSettlement>
     */
    public static function settleableForBranch(?int $branchId, ?string $cylinderType = null): Collection
    {
        return static::forBranch($branchId, $cylinderType)
            ->filter(fn (ReconciliationSettlement $settlement): bool => in_array($settlement->party, self::SETTLEABLE_PARTIES, true))
            ->values();
    }

    /**
     * One readable line per open settlement — used by the data-entry warning.
     *
     * @return array<int, string>
     */
    public static function lines(?int $branchId, ?string $cylinderType = null): array
    {
        return static::forBranch($branchId, $cylinderType)
            ->map(fn (ReconciliationSettlement $settlement): string => static::line($settlement))
            ->all();
    }

    public static function line(ReconciliationSettlement $settlement): string
    {
        $reconciliation = $settlement->reconciliation;

        $parts = array_filter([
            $reconciliation?->purchaseOrder?->do_number ? 'PO '.$reconciliation->purchaseOrder->do_number : null,
            $reconciliation?->loadingOrder?->do_number ? 'LO '.$reconciliation->loadingOrder->do_number : null,
            $reconciliation?->cylinder_type,
            $settlement->quantity.' unit',
            'Rp '.number_format((float) $settlement->unit_price, 0, ',', '.').'/unit',
            'Rp '.number_format((float) $settlement->amount, 0, ',', '.'),
            $settlement->type_label,
        ]);

        if ($settlement->applies_to_next_delivery && $settlement->sourcePurchaseOrder) {
            $parts[] = 'carry-forward dari '.$settlement->sourcePurchaseOrder->do_number;
        }

        return implode(' · ', $parts);
    }

    /**
     * The reference block for the next document's `notes` (D6). Transportir claims are
     * excluded because they are never settled by a branch document.
     */
    public static function noteReference(?int $branchId, ?string $cylinderType = null): ?string
    {
        $rows = static::settleableForBranch($branchId, $cylinderType);

        if ($rows->isEmpty()) {
            return null;
        }

        $lines = $rows->map(function (ReconciliationSettlement $settlement): string {
            $reconciliation = $settlement->reconciliation;

            $po = $reconciliation?->purchaseOrder?->do_number ?? '-';
            $lo = $reconciliation?->loadingOrder?->do_number;
            $type = $reconciliation?->cylinder_type;
            $price = 'Rp '.number_format((float) $settlement->unit_price, 0, ',', '.').'/unit';

            $reference = $po.($lo ? ' / LO '.$lo : '');

            return "[Settlement rekonsiliasi] {$reference} — {$settlement->quantity} unit {$type} @ {$price} ({$settlement->type_label})";
        });

        return $lines->prepend('Referensi rekonsiliasi tabung:')->implode("\n");
    }

    /**
     * Append the reference block to a document's `notes` (D6). Idempotent: a note
     * that already carries the reference is returned unchanged, and a document with
     * nothing outstanding keeps its original notes.
     */
    public static function appendReference(?string $notes, ?int $branchId, ?string $cylinderType = null): ?string
    {
        $reference = static::noteReference($branchId, $cylinderType);

        if ($reference === null) {
            return $notes;
        }

        $notes = trim((string) $notes);

        if ($notes !== '' && str_contains($notes, $reference)) {
            return $notes;
        }

        return $notes === '' ? $reference : $notes."\n".$reference;
    }
}
