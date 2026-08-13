<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseOrderResource\Pages;
use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\Expedition;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = DeliveryOrder::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'DO & Delivery';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'do_number';

    public static function getNavigationLabel(): string
    {
        return 'Purchase Order (PO)';
    }

    public static function getModelLabel(): string
    {
        return 'Purchase Order';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Purchase Orders';
    }

    public static function form(Form $form): Form
    {
        $user       = auth()->user();
        $isPusat    = $user->isOwnerPusat() || $user->isRegionalLeader();
        $mainBranch = Branch::mainBranch()->first();

        return $form->schema([
            Forms\Components\Section::make('Purchase Order Details')
                ->description('Request Tabung from Main Branch')
                ->schema([
                    Forms\Components\TextInput::make('do_number')
                        ->label('PO Number')
                        ->placeholder('e.g. PO2026-001')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(50)
                        ->default(function () {
                            $year  = date('Y');
                            $count = DeliveryOrder::purchaseOrders()->whereYear('created_at', $year)->count() + 1;
                            return 'PO' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
                        })
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
                ])->columns(2),

            // ----- Hidden auto-set fields -----
            Forms\Components\Hidden::make('document_type')
                ->default('po'),

            Forms\Components\Hidden::make('counterparty_type')
                ->default('branch'),

            Forms\Components\Hidden::make('origin_branch_id')
                ->default(fn() => $mainBranch?->id),

            Forms\Components\Hidden::make('destination_branch_id')
                ->default(fn() => $isPusat ? null : $user->branch_id),

            Forms\Components\Hidden::make('requested_by')
                ->default(fn() => auth()->id()),

            // ----- Counterparty section -----
            Forms\Components\Section::make('Counterparty (Main Branch)')
                ->description('The Main Branch this PO is addressed to')
                ->schema([
                    Forms\Components\TextInput::make('counterparty_name')
                        ->label('Main Branch Name')
                        ->placeholder('e.g. SUM Pusat')
                        ->default(fn() => $mainBranch?->name)
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('eta')
                        ->label('ETA (Estimated Time of Arrival)')
                        ->nullable()
                        ->columnSpan(1),
                ])->columns(2),

            // ----- Route & Expedition (pusat only) -----
            Forms\Components\Section::make('Route & Expedition')
                ->description('Origin, destination, and shipping details')
                ->visible(fn() => $isPusat)
                ->schema([
                    Forms\Components\Select::make('destination_branch_id')
                        ->label('Destination Branch / Tujuan')
                        ->options(Branch::active()->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\Select::make('expedition_id')
                        ->label('Expedition / Ekspedisi')
                        ->options(Expedition::where('status', 'active')->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->columnSpan(1),

                    Forms\Components\Select::make('vehicle_id')
                        ->label('Vehicle / Kendaraan')
                        ->options(
                            Vehicle::active()
                                ->orderBy('plate_number')
                                ->get()
                                ->mapWithKeys(fn($v) => [$v->id => $v->plate_number . ' — ' . $v->driver_name . ($v->expedition ? ' (' . $v->expedition->name . ')' : '')])
                        )
                        ->searchable()
                        ->nullable()
                        ->columnSpan(1),

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
                        ->columnSpan(1),
                ])->columns(2),

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
                $query->purchaseOrders()->with(['originBranch', 'destinationBranch', 'expedition', 'linkedDo']);

                if (! $isPusat && $user->branch_id) {
                    $query->where('destination_branch_id', $user->branch_id);
                }
            })
            ->columns([
                Tables\Columns\TextColumn::make('do_number')
                    ->label('PO Number')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('originBranch.name')
                    ->label('From (Pusat)')
                    ->searchable(),

                Tables\Columns\TextColumn::make('destinationBranch.name')
                    ->label('To (Branch)')
                    ->searchable(),

                Tables\Columns\TextColumn::make('cylinder_type')
                    ->label('Type')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('quantity_ordered')
                    ->label('Qty')
                    ->formatStateUsing(fn($state) => number_format($state) . ' pcs'),

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

                // Shipment status — shows the linked DO's status (auto-created from this PO)
                Tables\Columns\TextColumn::make('linkedDo.status')
                    ->label('Shipment Status')
                    ->badge()
                    ->color(fn(?string $state): string => match ($state) {
                        'draft'            => 'gray',
                        'pending_approval' => 'warning',
                        'approved'         => 'info',
                        'in_transit'       => 'primary',
                        'on_transportir'   => 'purple',
                        'delivered'        => 'success',
                        'cancelled'        => 'danger',
                        null               => 'gray',
                        default            => 'gray',
                    })
                    ->formatStateUsing(fn(?string $state) => match ($state) {
                        'draft'            => 'Draft',
                        'pending_approval' => 'Pending Approval',
                        'approved'         => 'Approved',
                        'in_transit'       => 'In Transit',
                        'on_transportir'   => 'On Transportir',
                        'delivered'        => 'Delivered',
                        'cancelled'        => 'Cancelled',
                        null               => 'Not yet shipped',
                        default            => $state,
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('eta')
                    ->label('ETA')
                    ->date('d M Y')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('order_date')
                    ->label('Order Date')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
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
                // Submit (Branch)
                Tables\Actions\Action::make('submit')
                    ->label('Submit')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(fn(DeliveryOrder $record) => $record->status === 'draft')
                    ->action(fn(DeliveryOrder $record) => $record->update(['status' => 'pending_approval']))
                    ->requiresConfirmation()
                    ->modalHeading('Submit for Approval?')
                    ->modalDescription('This will send the PO to the central office for approval.'),

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
                    ->modalHeading('Approve this Purchase Order?'),

                // In Transit (HQ)
                Tables\Actions\Action::make('mark_in_transit')
                    ->label('In Transit')
                    ->icon('heroicon-o-truck')
                    ->color('info')
                    ->visible(
                        fn(DeliveryOrder $record) =>
                        $record->status === 'approved' &&
                            auth()->user()?->canApproveOrders()
                    )
                    ->action(fn(DeliveryOrder $record) => $record->update(['status' => 'in_transit']))
                    ->requiresConfirmation()
                    ->modalHeading('Mark as In Transit?'),

                // Delivered (Branch - marks arrival)
                Tables\Actions\Action::make('mark_delivered')
                    ->label('Delivered')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(
                        fn(DeliveryOrder $record) =>
                        in_array($record->status, ['in_transit', 'on_transportir'])
                    )
                    ->form([
                        Forms\Components\TextInput::make('quantity_received')
                            ->label('Quantity Received / Jumlah Diterima')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->suffix('pcs'),
                        Forms\Components\DatePicker::make('received_date')
                            ->label('Date Received')
                            ->required()
                            ->default(today()),
                    ])
                    ->action(fn(DeliveryOrder $record, array $data) => $record->update([
                        'status'            => 'delivered',
                        'quantity_received' => $data['quantity_received'],
                        'received_date'     => $data['received_date'],
                    ]))
                    ->modalHeading('Confirm Delivery')
                    ->modalDescription('Mark this PO as arrived at the destination branch.'),

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

    /** Pusat cannot create POs — only approve/manage. */
    public static function canCreate(): bool
    {
        $user = auth()->user();

        return ! $user->isOwnerPusat() && ! $user->isRegionalLeader();
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'edit'   => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user) return false;

        // All roles can view Purchase Orders (branches create, pusat approves)
        return $user->canAccessPanel(app(\Filament\Panel::class));
    }
}
