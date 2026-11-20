<?php

namespace App\Filament\Resources\ReconciliationResource\RelationManagers;

use App\Models\DeliveryOrder;
use App\Models\Invoice;
use App\Models\ReconciliationSettlement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * BALI-21 / D6 (2026-11-20): the obligations of one reconciliation with the manual
 * Settle / Void workflow.
 *
 * Rows are produced by `CylinderReconciliationService` and are never created here.
 * "Settle" records which next document (Invoice / SO / LOT / LOE) carried the
 * settlement — receivables and payables are not touched (D6).
 */
class ReconciliationSettlementsRelationManager extends RelationManager
{
    protected static string $relationship = 'settlements';

    protected static ?string $title = 'Settlements — Penyelesaian';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('settlement_type')
            ->columns([
                Tables\Columns\TextColumn::make('type_label')
                    ->label('Obligation')
                    ->wrap(),

                Tables\Columns\TextColumn::make('party')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        ReconciliationSettlement::PARTY_HQ     => 'info',
                        ReconciliationSettlement::PARTY_BRANCH => 'warning',
                        default                                => 'gray',
                    }),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Qty'),

                Tables\Columns\TextColumn::make('unit_price')
                    ->label('Unit Price')
                    ->money('IDR'),

                Tables\Columns\TextColumn::make('amount')
                    ->money('IDR'),

                Tables\Columns\TextColumn::make('sourcePurchaseOrder.do_number')
                    ->label('Source PO')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\IconColumn::make('applies_to_next_delivery')
                    ->label('Next delivery')
                    ->boolean(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ReconciliationSettlement::statuses()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        ReconciliationSettlement::STATUS_SETTLED => 'success',
                        ReconciliationSettlement::STATUS_VOID    => 'danger',
                        default                                  => 'warning',
                    }),

                Tables\Columns\TextColumn::make('settled_document_label')
                    ->label('Settled by')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('settled_at')
                    ->label('Settled at')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('notes')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(ReconciliationSettlement::statuses()),

                Tables\Filters\SelectFilter::make('party')
                    ->label('Party')
                    ->options([
                        ReconciliationSettlement::PARTY_HQ          => 'HQ',
                        ReconciliationSettlement::PARTY_BRANCH      => 'Branch',
                        ReconciliationSettlement::PARTY_TRANSPORTIR => 'Transportir',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('settle')
                    ->label('Settle')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (ReconciliationSettlement $record): bool => $record->isOpen())
                    ->form([
                        Forms\Components\Select::make('settled_by_type')
                            ->label('Settled by document')
                            ->options(ReconciliationSettlement::documents())
                            ->required()
                            ->live(),

                        Forms\Components\Select::make('settled_by_id')
                            ->label('Document')
                            ->options(fn (Forms\Get $get) => $this->documentOptions($get('settled_by_type')))
                            ->searchable()
                            // T13-05: search runs against the database, so a number that is
                            // not in the latest 100 documents is still findable.
                            ->getSearchResultsUsing(fn (string $search, Forms\Get $get): array => $this->searchDocuments($get('settled_by_type'), $search))
                            ->getOptionLabelUsing(fn ($value, Forms\Get $get): ?string => $this->documentLabel($get('settled_by_type'), $value))
                            ->required()
                            ->visible(fn (Forms\Get $get): bool => filled($get('settled_by_type'))),

                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan penyelesaian (opsional)')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->action(function (ReconciliationSettlement $record, array $data): void {
                        $settled = $record->markSettled(
                            $data['settled_by_type'],
                            (int) $data['settled_by_id'],
                            auth()->id(),
                        );

                        if (filled($data['notes'] ?? null)) {
                            $record->forceFill([
                                'notes' => trim(((string) $record->notes)."\n".trim($data['notes'])),
                            ])->save();
                        }

                        Notification::make()
                            ->title($settled ? 'Settlement ditandai selesai' : 'Settlement sudah tidak open')
                            ->body($record->fresh()->settled_document_label ?? '')
                            ->{$settled ? 'success' : 'warning'}()
                            ->send();
                    }),

                Tables\Actions\Action::make('void')
                    ->label('Void')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (ReconciliationSettlement $record): bool => $record->status !== ReconciliationSettlement::STATUS_VOID)
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Alasan void')
                            ->rows(2)
                            ->required(),
                    ])
                    ->action(function (ReconciliationSettlement $record, array $data): void {
                        $record->markVoid(auth()->id(), $data['reason']);

                        Notification::make()
                            ->title('Settlement di-void')
                            ->warning()
                            ->send();
                    }),
            ])
            ->paginated(false);
    }

    /**
     * Next documents of the branch that could have carried this settlement.
     *
     * @return array<int|string, string>
     */
    private function documentOptions(?string $type): array
    {
        if (blank($type)) {
            return [];
        }

        $branchId = $this->getOwnerRecord()->branch_id;

        return match ($type) {
            ReconciliationSettlement::DOCUMENT_INVOICE => Invoice::query()
                ->where('branch_id', $branchId)
                ->orderByDesc('issue_date')
                ->limit(100)
                ->pluck('invoice_number', 'id')
                ->all(),

            ReconciliationSettlement::DOCUMENT_SALES_ORDER => DeliveryOrder::salesOrders()
                ->where('destination_branch_id', $branchId)
                ->orderByDesc('order_date')
                ->limit(100)
                ->pluck('do_number', 'id')
                ->all(),

            ReconciliationSettlement::DOCUMENT_LOADING_ORDER => DeliveryOrder::loadingOrders()
                ->where('destination_branch_id', $branchId)
                ->orderByDesc('order_date')
                ->limit(100)
                ->pluck('do_number', 'id')
                ->all(),

            default => [],
        };
    }
    /**
     * T13-05: number lookup for the Settle dialog. Filament's plain `searchable()`
     * on a select only filters the loaded options; this queries the table instead so
     * older POs / SOs / LOs stay reachable by typing their number.
     *
     * @return array<int|string, string>
     */
    private function searchDocuments(?string $type, string $search): array
    {
        $term = trim($search);

        if ($term === '') {
            return $this->documentOptions($type);
        }

        if (blank($type)) {
            return [];
        }

        $branchId = $this->getOwnerRecord()->branch_id;
        $like = "%{$term}%";

        return match ($type) {
            ReconciliationSettlement::DOCUMENT_INVOICE => Invoice::query()
                ->where('branch_id', $branchId)
                ->where('invoice_number', 'like', $like)
                ->orderByDesc('issue_date')
                ->limit(50)
                ->pluck('invoice_number', 'id')
                ->all(),

            ReconciliationSettlement::DOCUMENT_SALES_ORDER => DeliveryOrder::salesOrders()
                ->where('destination_branch_id', $branchId)
                ->where('do_number', 'like', $like)
                ->orderByDesc('order_date')
                ->limit(50)
                ->pluck('do_number', 'id')
                ->all(),

            ReconciliationSettlement::DOCUMENT_LOADING_ORDER => DeliveryOrder::loadingOrders()
                ->where('destination_branch_id', $branchId)
                ->where('do_number', 'like', $like)
                ->orderByDesc('order_date')
                ->limit(50)
                ->pluck('do_number', 'id')
                ->all(),

            default => [],
        };
    }

    /** Label of the already-selected document (needed once search is server-side). */
    private function documentLabel(?string $type, mixed $id): ?string
    {
        if (blank($type) || blank($id)) {
            return null;
        }

        return match ($type) {
            ReconciliationSettlement::DOCUMENT_INVOICE       => Invoice::query()->whereKey($id)->value('invoice_number'),
            ReconciliationSettlement::DOCUMENT_SALES_ORDER   => DeliveryOrder::salesOrders()->whereKey($id)->value('do_number'),
            ReconciliationSettlement::DOCUMENT_LOADING_ORDER => DeliveryOrder::loadingOrders()->whereKey($id)->value('do_number'),
            default                                          => null,
        };
    }


}
