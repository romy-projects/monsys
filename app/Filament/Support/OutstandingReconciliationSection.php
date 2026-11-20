<?php

namespace App\Filament\Support;

use App\Models\ReconciliationSettlement;
use App\Support\OutstandingReconciliations;
use Filament\Forms;
use Illuminate\Support\HtmlString;

/**
 * D7 (2026-11-20): display-only warning of a branch's open reconciliation
 * credits/claims on the documents that settle them (Invoice / SO / LOT / LOE).
 *
 * The section renders nothing when the branch has no open settlement for the
 * selected cylinder type; it never alters the submitted data.
 */
class OutstandingReconciliationSection
{
    public static function make(
        string $branchField = 'destination_branch_id',
        string $cylinderTypeField = 'cylinder_type',
    ): Forms\Components\Section {
        return Forms\Components\Section::make('Rekonsiliasi Tabung — Saldo Terbuka')
            ->description('Informasi saja (D7): sistem tidak memotong qty/harga otomatis. Selesaikan selisih lewat dokumen ini dan tulis referensinya di Notes (D6).')
            ->visible(fn (Forms\Get $get): bool => static::rows($get, $branchField, $cylinderTypeField)->isNotEmpty())
            ->schema([
                Forms\Components\Placeholder::make('outstanding_reconciliations')
                    ->label('Saldo belum diselesaikan')
                    ->content(fn (Forms\Get $get): HtmlString => static::html($get, $branchField, $cylinderTypeField))
                    ->columnSpanFull(),
            ]);
    }

    private static function rows(
        Forms\Get $get,
        string $branchField,
        string $cylinderTypeField,
    ) {
        return OutstandingReconciliations::forBranch(
            filled($get($branchField)) ? (int) $get($branchField) : null,
            filled($get($cylinderTypeField)) ? (string) $get($cylinderTypeField) : null,
        );
    }

    private static function html(
        Forms\Get $get,
        string $branchField,
        string $cylinderTypeField,
    ): HtmlString {
        $items = static::rows($get, $branchField, $cylinderTypeField)
            ->map(fn (ReconciliationSettlement $settlement): string => '<li>'.e(OutstandingReconciliations::line($settlement)).'</li>')
            ->implode('');

        $reference = OutstandingReconciliations::noteReference(
            filled($get($branchField)) ? (int) $get($branchField) : null,
            filled($get($cylinderTypeField)) ? (string) $get($cylinderTypeField) : null,
        );

        return new HtmlString(
            '<div><ul style="list-style:disc;margin-left:1.25rem">'.$items.'</ul>'
            .($reference !== null
                ? '<p style="margin-top:.75rem"><strong>Referensi untuk Notes:</strong></p><pre style="white-space:pre-wrap;margin:0">'.e($reference).'</pre>'
                : '')
            .'</div>'
        );
    }
}
