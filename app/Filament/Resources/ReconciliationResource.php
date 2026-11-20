<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReconciliationResource\Pages;
use App\Models\Branch;
use App\Models\CylinderReconciliation;
use App\Models\DeliveryOrder;
use App\Services\CylinderReconciliationService;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * BALI-18…22 — read-only view of cylinder reconciliations plus a manual
 * "Rekonsiliasi" trigger for a delivered Loading Order.
 */
class ReconciliationResource extends Resource
{
    protected static ?string $model = CylinderReconciliation::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'DO & Delivery';

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return 'Rekonsiliasi Tabung';
    }

    public static function getModelLabel(): string
    {
        return 'Rekonsiliasi Tabung';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Rekonsiliasi Tabung';
    }

    /** Reconciliations are produced by the LO-delivered observer; never created by hand. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Ringkasan Rekonsiliasi')
                ->schema([
                    Infolists\Components\TextEntry::make('purchaseOrder.do_number')->label('PO'),
                    Infolists\Components\TextEntry::make('salesOrder.do_number')->label('SO')->placeholder('—'),
                    Infolists\Components\TextEntry::make('loadingOrder.do_number')->label('LO'),
                    Infolists\Components\TextEntry::make('branch.name')->label('Branch'),
                    Infolists\Components\TextEntry::make('cylinder_type')->label('Type')->badge(),
                    Infolists\Components\TextEntry::make('case_type')
                        ->label('Case')
                        ->badge()
                        ->formatStateUsing(fn (CylinderReconciliation $record) => $record->case_label),
                    Infolists\Components\TextEntry::make('quantity_paid')->label('Paid'),
                    Infolists\Components\TextEntry::make('quantity_redeemed')->label('Redeemed'),
                    Infolists\Components\TextEntry::make('quantity_delivered')->label('Delivered'),
                    Infolists\Components\TextEntry::make('quantity_unredeemed')->label('Unredeemed'),
                    Infolists\Components\TextEntry::make('quantity_short_delivered')->label('Short Delivered'),
                    Infolists\Components\TextEntry::make('quantity_over_delivered')->label('Over Delivered'),
                    Infolists\Components\TextEntry::make('unit_price')->label('PO Price')->money('IDR'),
                    Infolists\Components\TextEntry::make('created_at')->label('Created')->dateTime('d M Y H:i'),
                ])
                ->columns(4),

            Infolists\Components\Section::make('Settlements')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('settlements')
                        ->label('')
                        ->placeholder('Tidak ada selisih — rantai PO/SO/LO seimbang.')
                        ->schema([
                            Infolists\Components\TextEntry::make('party')->badge(),
                            Infolists\Components\TextEntry::make('type_label')->label('Obligation'),
                            Infolists\Components\TextEntry::make('quantity'),
                            Infolists\Components\TextEntry::make('amount')->money('IDR'),
                            Infolists\Components\TextEntry::make('status')->badge(),
                        ])
                        ->columns(5),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = auth()->user();

        return $table
            ->modifyQueryUsing(function (Builder $query) use ($user) {
                $query->with(['purchaseOrder', 'loadingOrder', 'salesOrder', 'branch']);

                if (! $user?->isOwnerPusat() && ! $user?->isRegionalLeader()) {
                    $query->where('branch_id', $user?->branch_id);
                }
            })
            ->columns([
                Tables\Columns\TextColumn::make('purchaseOrder.do_number')
                    ->label('PO')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('loadingOrder.do_number')
                    ->label('LO')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('cylinder_type')
                    ->label('Type')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('quantity_paid')
                    ->label('Paid')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('quantity_redeemed')
                    ->label('Redeemed')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('quantity_delivered')
                    ->label('Delivered')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('case_type')
                    ->label('Case')
                    ->badge()
                    ->formatStateUsing(fn (CylinderReconciliation $record) => $record->case_label)
                    ->color(fn (string $state): string => match ($state) {
                        'case_1' => 'warning',
                        'case_2' => 'danger',
                        'case_3' => 'info',
                        default  => 'success',
                    }),

                Tables\Columns\TextColumn::make('settlements_count')
                    ->label('Settlements')
                    ->counts('settlements')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('unit_price')
                    ->label('PO Price')
                    ->money('IDR')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('case_type')
                    ->label('Discrepancy')
                    ->options([
                        'balanced' => 'Balanced',
                        'case_1'   => 'Case 1 — Unredeemed PO',
                        'case_2'   => 'Case 2 — Short Delivery',
                        'case_3'   => 'Case 3 — Over Delivery',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'open'    => 'Open',
                        'settled' => 'Settled',
                    ]),

                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(fn () => Branch::orderBy('name')->pluck('name', 'id'))
                    ->visible(fn () => auth()->user()?->isOwnerPusat() || auth()->user()?->isRegionalLeader()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('reconcile')
                    ->label('Rekonsiliasi LO')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn () => auth()->user()?->isOwnerPusat() || auth()->user()?->isRegionalLeader())
                    ->form([
                        Forms\Components\Select::make('loading_order_id')
                            ->label('Delivered Loading Order')
                            ->options(fn () => DeliveryOrder::loadingOrders()
                                ->where('status', 'delivered')
                                ->whereNotNull('po_number')
                                ->orderByDesc('id')
                                ->limit(100)
                                ->pluck('do_number', 'id'))
                            ->searchable()
                            ->required()
                            ->helperText('Reprocessing is safe — an existing reconciliation is updated, never duplicated.'),
                    ])
                    ->action(function (array $data): void {
                        $lo = DeliveryOrder::find($data['loading_order_id']);

                        $reconciliation = $lo
                            ? app(CylinderReconciliationService::class)->reconcile($lo, auth()->id())
                            : null;

                        if (! $reconciliation) {
                            Notification::make()
                                ->title('Tidak dapat direkonsiliasi')
                                ->body('LO ini tidak punya referensi PO atau cabang tujuan.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Rekonsiliasi tersimpan')
                            ->body("PO #{$reconciliation->purchaseOrder?->do_number} — {$reconciliation->case_label}.")
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReconciliations::route('/'),
            'view'  => Pages\ViewReconciliation::route('/{record}'),
        ];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->canAccessPanel(app(\Filament\Panel::class))
            && ($user->canViewFinance() || $user->isOwnerCabang() || $user->isStaffGudang());
    }
}
