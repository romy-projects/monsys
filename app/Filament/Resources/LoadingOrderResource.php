<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoadingOrderResource\Pages;
use App\Filament\Support\OutstandingReconciliationSection;
use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\Expedition;
use App\Models\Transportir;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LoadingOrderResource extends Resource
{
    protected static ?string $model = DeliveryOrder::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'DO & Delivery';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'do_number';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.lo_transportir');
    }

    public static function getModelLabel(): string
    {
        return 'Loading Order Transportir';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Loading Orders Transportir';
    }

    public static function form(Form $form): Form
    {
        $user       = auth()->user();
        $isPusat    = $user->isOwnerPusat() || $user->isRegionalLeader();
        $mainBranch = Branch::mainBranch()->first();

        return $form->schema([
            Forms\Components\Section::make('Loading Order Details')
                ->description('Loading instruction for the Transportir to take Tabung from Pertamina. The Transportir arranges its own expedition and container.')
                ->schema([
                    Forms\Components\TextInput::make('do_number')
                        ->label('LO Number')
                        ->placeholder('Auto-generated on save (e.g. LOT2026-001)')
                        ->unique(ignoreRecord: true)
                        ->maxLength(50)
                        ->helperText('Left empty, the number is allocated server-side so concurrent users cannot collide.')
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('order_date')
                        ->label('Order Date')
                        ->required()
                        ->default(today())
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

                    Forms\Components\Select::make('po_number')
                        ->label('Source PO Number / No. PO')
                        ->options(fn () => DeliveryOrder::purchaseOrders()
                            ->orderByDesc('order_date')
                            ->limit(500)
                            ->pluck('do_number', 'do_number'))
                        ->searchable()
                        ->required()
                        ->helperText('Every Transportir LO must continue an approved Purchase Order.')
                        ->columnSpan(1),

                    Forms\Components\Select::make('destination_branch_id')
                        ->label('Destination Branch / Pangkalan Tujuan')
                        ->options(fn () => Branch::active()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->columnSpan(1),
                ])->columns(2),

            // ----- Hidden auto-set fields -----
            Forms\Components\Hidden::make('document_type')
                ->default('lo'),

            Forms\Components\Hidden::make('lo_kind')
                ->default('transportir'),

            Forms\Components\Hidden::make('cost_borne_by')
                ->default('transporter'),

            Forms\Components\Hidden::make('counterparty_type')
                ->default('pertamina'),

            Forms\Components\Hidden::make('origin_branch_id')
                ->default(fn() => $mainBranch?->id),

            Forms\Components\Hidden::make('requested_by')
                ->default(fn() => auth()->id()),

            // ----- Reference section -----
            Forms\Components\Section::make('Reference & Counterparty')
                ->description('Link this LO to its source SO/DO and counterparty')
                ->schema([
                    Forms\Components\TextInput::make('so_number')
                        ->label('Source SO Number')
                        ->placeholder('e.g. SO2026-001')
                        ->nullable()
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('counterparty_name')
                        ->label('Pertamina Name')
                        ->placeholder('e.g. Pertamina Patra Niaga')
                        ->default('Pertamina')
                        ->required()
                        ->columnSpan(1),
                ])->columns(2),

            // ----- Loading section -----
            Forms\Components\Section::make('Loading & Transportir')
                ->description('Transportir and loading information')
                ->visible(fn() => $isPusat)
                ->schema([
                    Forms\Components\DatePicker::make('loading_date')
                        ->label('Loading Date')
                        ->nullable()
                        ->columnSpan(1),

                    Forms\Components\Select::make('loaded_by')
                        ->label('Loaded By')
                        ->options(User::where('status', 'active')->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->columnSpan(1),

                    Forms\Components\Select::make('transportir_id')
                        ->label('Transportir')
                        ->options(Transportir::where('status', 'active')->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->helperText('The Transportir that will take the cylinders from Pertamina.')
                        ->columnSpan(1),

                    Forms\Components\Select::make('expedition_id')
                        ->label('Expedition / Ekspedisi')
                        ->options(Expedition::where('status', 'active')->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->helperText('Optional — the Transportir reports its expedition after loading.')
                        ->columnSpan(1),

                    // Phase 9: Vehicle & Driver input hidden (not mandatory on LO).
                    // Re-enable by uncommenting the block below.
                    // Forms\Components\Select::make('vehicle_id')
                    //     ->label('Vehicle / Kendaraan')
                    //     ->options(
                    //         Vehicle::active()
                    //             ->orderBy('plate_number')
                    //             ->get()
                    //             ->mapWithKeys(fn($v) => [$v->id => $v->plate_number . ' — ' . $v->driver_name . ($v->expedition ? ' (' . $v->expedition->name . ')' : '')])
                    //     )
                    //     ->searchable()
                    //     ->nullable()
                    //     ->columnSpan(1),

                    Forms\Components\TextInput::make('transportir_name')
                        ->label('Transportir Name (ad-hoc)')
                        ->placeholder('e.g. Tatik, Pak Tri, etc.')
                        ->nullable()
                        ->maxLength(255)
                        ->helperText('For carriers not registered in Expedition master data')
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('container_number')
                        ->label('Container Number / No Kontainer')
                        ->nullable()
                        ->helperText('Optional on a Transportir LO — filled in later via "Update Expedition Details".')
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('eta')
                        ->label('ETA (Estimated Time of Arrival)')
                        ->nullable()
                        ->columnSpan(1),
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
        $user    = auth()->user();
        $isPusat = $user->isOwnerPusat() || $user->isRegionalLeader();

        return $table
            ->modifyQueryUsing(function (Builder $query) use ($user, $isPusat) {
                $query->transportirLoadingOrders()->with(['originBranch', 'destinationBranch', 'expedition', 'transportir', 'loadedBy']);

                if (! $isPusat && $user->branch_id) {
                    $query->where('origin_branch_id', $user->branch_id);
                }
            })
            ->columns([
                Tables\Columns\TextColumn::make('do_number')
                    ->label('LO Number')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('so_number')
                    ->label('Source SO')
                    ->searchable(),

                Tables\Columns\TextColumn::make('po_number')
                    ->label('Source PO')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('destinationBranch.name')
                    ->label('To (Branch)')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('transportir.name')
                    ->label('Transportir')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('counterparty_name')
                    ->label('Pertamina')
                    ->searchable(),

                Tables\Columns\TextColumn::make('cylinder_type')
                    ->label('Type')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('quantity_ordered')
                    ->label('Qty')
                    ->formatStateUsing(fn($state) => number_format($state) . ' pcs'),

                Tables\Columns\TextColumn::make('container_number')
                    ->label('Container')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('loading_date')
                    ->label('Loading Date')
                    ->date('d M Y')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('loadedBy.name')
                    ->label('Loaded By')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('transportir_name')
                    ->label('Transportir')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'draft'            => 'gray',
                        'pending_approval' => 'warning',
                        'approved'         => 'info',
                        'in_transit'       => 'primary',
                        'on_transportir'   => 'purple',
                        'delivered'        => 'success',
                        'cancelled'        => 'danger',
                        default            => 'gray',
                    })
                    ->formatStateUsing(fn($state) => match ($state) {
                        'draft'            => 'Draft',
                        'pending_approval' => 'Pending Approval',
                        'approved'         => 'Approved',
                        'in_transit'       => 'In Transit',
                        'on_transportir'   => 'On Transportir',
                        'delivered'        => 'Delivered',
                        'cancelled'        => 'Cancelled',
                        default            => $state,
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft'            => 'Draft',
                        'pending_approval' => 'Pending Approval',
                        'approved'         => 'Approved',
                        'in_transit'       => 'In Transit',
                        'on_transportir'   => 'On Transportir',
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
                // Submit
                Tables\Actions\Action::make('submit')
                    ->label('Submit')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(fn(DeliveryOrder $record) => $record->status === 'draft')
                    ->action(fn(DeliveryOrder $record) => $record->update(['status' => 'pending_approval']))
                    ->requiresConfirmation()
                    ->modalHeading('Submit for Approval?'),

                // Approve (HQ only)
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(
                        fn(DeliveryOrder $record) =>
                        $record->status === 'pending_approval' &&
                            auth()->user()?->canApproveOrders()
                    )
                    ->action(fn(DeliveryOrder $record) => $record->update([
                        'status'      => 'approved',
                        'approved_by' => auth()->id(),
                        'approved_at' => now(),
                    ]))
                    ->requiresConfirmation()
                    ->modalHeading('Approve this Loading Order?'),

                // Mark Loaded (HQ)
                Tables\Actions\Action::make('mark_loaded')
                    ->label('Mark Loaded')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->color('info')
                    ->visible(
                        fn(DeliveryOrder $record) =>
                        $record->status === 'approved' &&
                            auth()->user()?->canApproveOrders()
                    )
                    ->form([
                        Forms\Components\DatePicker::make('loading_date')
                            ->label('Loading Date')
                            ->required()
                            ->default(today()),
                    ])
                    ->action(fn(DeliveryOrder $record, array $data) => $record->update([
                        'status'        => 'in_transit',
                        'loading_date'  => $data['loading_date'],
                        'loaded_by'     => auth()->id(),
                    ]))
                    ->requiresConfirmation()
                    ->modalHeading('Mark as Loaded & In Transit?'),

                // Cancel (HQ only)
                Tables\Actions\Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(
                        fn(DeliveryOrder $record) =>
                        ! in_array($record->status, ['delivered', 'cancelled']) &&
                            auth()->user()?->canApproveOrders()
                    )
                    ->action(fn(DeliveryOrder $record) => $record->update(['status' => 'cancelled']))
                    ->requiresConfirmation(),

                // Transportir reports its expedition + container after the LO is created (BALI-11).
                Tables\Actions\Action::make('update_expedition_details')
                    ->label('Update Expedition Details')
                    ->icon('heroicon-o-truck')
                    ->color('gray')
                    ->visible(fn (DeliveryOrder $record) => $record->status !== 'cancelled'
                        && auth()->user()?->canApproveOrders())
                    ->form([
                        Forms\Components\Select::make('expedition_id')
                            ->label('Expedition / Ekspedisi')
                            ->options(Expedition::where('status', 'active')->pluck('name', 'id'))
                            ->searchable()
                            ->nullable(),
                        Forms\Components\TextInput::make('container_number')
                            ->label('Container Number / No Kontainer')
                            ->nullable(),
                        Forms\Components\TextInput::make('transportir_name')
                            ->label('Transportir Name (ad-hoc)')
                            ->nullable(),
                    ])
                    ->fillForm(fn (DeliveryOrder $record) => [
                        'expedition_id'    => $record->expedition_id,
                        'container_number' => $record->container_number,
                        'transportir_name' => $record->transportir_name,
                    ])
                    ->action(fn (DeliveryOrder $record, array $data) => $record->update($data))
                    ->modalHeading('Update Expedition Details')
                    ->modalDescription('Reported by the transportir after the loading has been arranged.')
                    ->successNotificationTitle('Expedition details updated'),

                Tables\Actions\EditAction::make()
                    ->visible(fn(DeliveryOrder $record) => $record->status === 'draft'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('order_date', 'desc');
    }

    /** Only Pusat/Regional can create LOs. */
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
            'index'  => Pages\ListLoadingOrders::route('/'),
            'create' => Pages\CreateLoadingOrder::route('/create'),
            'edit'   => Pages\EditLoadingOrder::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) return false;

        // Only Pusat/Regional can view Loading Orders
        return $user->canAccessPanel(app(\Filament\Panel::class))
            && ($user->isOwnerPusat() || $user->isRegionalLeader());
    }
}
