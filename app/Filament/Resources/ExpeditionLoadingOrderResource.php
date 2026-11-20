<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExpeditionLoadingOrderResource\Pages;
use App\Filament\Support\OutstandingReconciliationSection;
use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\Expedition;
use App\Models\Transportir;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Expedition Loading Order (LO Expedisi) — BALI-11 / BALI-12 / BALI-13.
 *
 * Reference chain: PO → LO Transportir (LOT) → LO Expedisi (LOE).
 * HQ/Pusat chooses the expedition and records the flat delivery fee, so the
 * container number and the parent Transportir LO are mandatory here.
 */
class ExpeditionLoadingOrderResource extends Resource
{
    protected static ?string $model = DeliveryOrder::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'DO & Delivery';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'do_number';

    protected static ?string $slug = 'expedition-loading-orders';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.lo_expedition');
    }

    public static function getModelLabel(): string
    {
        return 'Loading Order Expedisi';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Loading Orders Expedisi';
    }

    public static function form(Form $form): Form
    {
        $mainBranch = Branch::mainBranch()->first();

        return $form->schema([
            Forms\Components\Section::make('LO Expedisi Details')
                ->description('Expedition arranged by HQ/Pusat for a branch/pangkalan.')
                ->schema([
                    Forms\Components\TextInput::make('do_number')
                        ->label('LO Number')
                        ->placeholder('Auto-generated on save (e.g. LOE2026-001)')
                        ->unique(ignoreRecord: true)
                        ->maxLength(50)
                        ->helperText('Left empty, the number is allocated server-side.')
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('order_date')
                        ->label('Order Date')
                        ->required()
                        ->default(today())
                        ->columnSpan(1),

                    Forms\Components\Select::make('transportir_lo_number')
                        ->label('Transportir LO / No. LO Transportir')
                        ->options(fn () => DeliveryOrder::transportirLoadingOrders()
                            ->orderByDesc('order_date')
                            ->limit(500)
                            ->pluck('do_number', 'do_number'))
                        ->searchable()
                        ->required()
                        ->helperText('The Transportir LO this expedition continues.')
                        ->columnSpan(1),

                    Forms\Components\Select::make('destination_branch_id')
                        ->label('Destination Branch / Pangkalan Tujuan')
                        ->options(fn () => Branch::active()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\Select::make('cylinder_type')
                        ->label('Cylinder Type / Jenis Tabung')
                        ->options([
                            '3kg'   => '3 kg',
                            '5.5kg' => '5.5 kg',
                            '12kg'  => '12 kg',
                            '50kg'  => '50 kg',
                        ])
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('quantity_ordered')
                        ->label('Quantity Ordered / Jumlah')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->suffix('pcs')
                        ->columnSpan(1),
                ])->columns(2),

            // ----- Hidden auto-set fields -----
            Forms\Components\Hidden::make('document_type')
                ->default('lo'),

            Forms\Components\Hidden::make('lo_kind')
                ->default('expedition'),

            Forms\Components\Hidden::make('counterparty_type')
                ->default('branch'),

            Forms\Components\Hidden::make('origin_branch_id')
                ->default(fn () => $mainBranch?->id),

            Forms\Components\Hidden::make('requested_by')
                ->default(fn () => auth()->id()),

            // The expedition cost is always HQ's (Pusat) on an Expedition LO.
            Forms\Components\Hidden::make('cost_borne_by')
                ->default('sum'),

            Forms\Components\Section::make('Expedition & Delivery Fee')
                ->description('Who carries the cylinders, in which container, and what HQ pays for the delivery.')
                ->schema([
                    Forms\Components\Select::make('expedition_id')
                        ->label('Expedition / Ekspedisi')
                        ->options(Expedition::where('status', 'active')->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('container_number')
                        ->label('Container Number / No Kontainer')
                        ->required()
                        ->maxLength(100)
                        ->helperText('Mandatory on an Expedition LO (BALI-12).')
                        ->columnSpan(1),

                    Forms\Components\Select::make('transportir_id')
                        ->label('Transportir')
                        ->options(Transportir::where('status', 'active')->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->helperText('Optional — normally inherited from the Transportir LO.')
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('loading_date')
                        ->label('Loading Date')
                        ->nullable()
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('expedition_cost')
                        ->label('Expedition Cost / Biaya Ekspedisi')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->prefix('Rp')
                        ->helperText('Flat delivery fee for this LO — not multiplied by the quantity.')
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('eta')
                        ->label('ETA (Estimated Time of Arrival)')
                        ->nullable()
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('expedition_notes')
                        ->label('Expedition Notes / Catatan Ekspedisi')
                        ->maxLength(255)
                        ->nullable()
                        ->columnSpanFull(),
                ])->columns(2),

            // D7: open reconciliation credits/claims of the destination branch (display only).
            OutstandingReconciliationSection::make(),

            Forms\Components\Section::make('Notes')
                ->schema([
                    Forms\Components\Textarea::make('notes')
                        ->label('Notes / Catatan')
                        ->nullable()
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->expeditionLoadingOrders()
                ->with(['destinationBranch', 'expedition', 'transportir', 'loadedBy']))
            ->columns([
                Tables\Columns\TextColumn::make('do_number')
                    ->label('LO Number')
                    ->badge()
                    ->color('warning')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('transportir_lo_number')
                    ->label('Transportir LO')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('destinationBranch.name')
                    ->label('To (Branch)')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('expedition.name')
                    ->label('Expedition')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('container_number')
                    ->label('Container')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('cylinder_type')
                    ->label('Type')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('quantity_ordered')
                    ->label('Qty')
                    ->formatStateUsing(fn ($state) => number_format((int) $state) . ' pcs'),

                Tables\Columns\TextColumn::make('expedition_cost')
                    ->label('Expedition Cost')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format((float) $state, 0, ',', '.'))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft'            => 'gray',
                        'pending_approval' => 'warning',
                        'approved'         => 'info',
                        'in_transit'       => 'primary',
                        'on_transportir'   => 'purple',
                        'delivered'        => 'success',
                        'cancelled'        => 'danger',
                        default            => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'draft'            => 'Draft',
                        'pending_approval' => 'Pending Approval',
                        'approved'         => 'Approved',
                        'in_transit'       => 'In Transit',
                        'on_transportir'   => 'On Transportir',
                        'delivered'        => 'Delivered',
                        'cancelled'        => 'Cancelled',
                        default            => $state,
                    }),

                Tables\Columns\TextColumn::make('order_date')
                    ->label('Order Date')
                    ->date('d M Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft'            => 'Draft',
                        'pending_approval' => 'Pending Approval',
                        'approved'         => 'Approved',
                        'in_transit'       => 'In Transit',
                        'delivered'        => 'Delivered',
                        'cancelled'        => 'Cancelled',
                    ]),

                Tables\Filters\SelectFilter::make('cylinder_type')
                    ->options([
                        '3kg'   => '3 kg',
                        '5.5kg' => '5.5 kg',
                        '12kg'  => '12 kg',
                        '50kg'  => '50 kg',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('submit')
                    ->label('Submit')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(fn (DeliveryOrder $record) => $record->status === 'draft')
                    ->action(fn (DeliveryOrder $record) => $record->update(['status' => 'pending_approval']))
                    ->requiresConfirmation(),

                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (DeliveryOrder $record) => $record->status === 'pending_approval'
                        && auth()->user()?->canApproveOrders())
                    ->action(fn (DeliveryOrder $record) => $record->update([
                        'status'      => 'approved',
                        'approved_by' => auth()->id(),
                        'approved_at' => now(),
                    ]))
                    ->requiresConfirmation(),

                Tables\Actions\Action::make('mark_loaded')
                    ->label('Mark Loaded')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->color('info')
                    ->visible(fn (DeliveryOrder $record) => $record->status === 'approved'
                        && auth()->user()?->canApproveOrders())
                    ->form([
                        Forms\Components\DatePicker::make('loading_date')
                            ->label('Loading Date')
                            ->required()
                            ->default(today()),
                    ])
                    ->action(fn (DeliveryOrder $record, array $data) => $record->update([
                        'status'       => 'in_transit',
                        'loading_date' => $data['loading_date'],
                        'loaded_by'    => auth()->id(),
                    ]))
                    ->requiresConfirmation(),

                Tables\Actions\Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (DeliveryOrder $record) => ! in_array($record->status, ['delivered', 'cancelled'], true)
                        && auth()->user()?->canApproveOrders())
                    ->action(fn (DeliveryOrder $record) => $record->update(['status' => 'cancelled']))
                    ->requiresConfirmation(),

                Tables\Actions\EditAction::make()
                    ->visible(fn (DeliveryOrder $record) => $record->status === 'draft'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('order_date', 'desc');
    }

    /** Only Pusat/Regional can create Expedition LOs. */
    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user->isOwnerPusat() || $user->isRegionalLeader();
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListExpeditionLoadingOrders::route('/'),
            'create' => Pages\CreateExpeditionLoadingOrder::route('/create'),
            'edit'   => Pages\EditExpeditionLoadingOrder::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) return false;

        return $user->canAccessPanel(app(\Filament\Panel::class))
            && ($user->isOwnerPusat() || $user->isRegionalLeader());
    }
}
