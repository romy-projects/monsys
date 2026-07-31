<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeliveryOrderResource\Pages;
use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\Expedition;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DeliveryOrderResource extends Resource
{
    protected static ?string $model = DeliveryOrder::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'DO & Delivery';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'do_number';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.do_request');
    }

    public static function getModelLabel(): string
    {
        return 'Delivery Order';
    }

    public static function form(Form $form): Form
    {
        $user   = auth()->user();
        $isPusat = $user->isOwnerPusat() || $user->isRegionalLeader();
        $mainBranch = Branch::mainBranch()->first();

        return $form->schema([
            Forms\Components\Section::make('DO Details')
                ->description('Basic delivery order information')
                ->schema([
                    Forms\Components\TextInput::make('do_number')
                        ->label('DO Number')
                        ->placeholder('e.g. DO2026-001')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(50)
                        ->default(function () {
                            $year  = date('Y');
                            $count = DeliveryOrder::whereYear('created_at', $year)->count() + 1;
                            return 'DO' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
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

            // ----- Hidden auto-set fields for non-pusat users -----
            Forms\Components\Hidden::make('order_type')
                ->default('inter_branch'),

            Forms\Components\Hidden::make('origin_branch_id')
                ->default(fn() => $mainBranch?->id),

            Forms\Components\Hidden::make('destination_branch_id')
                ->default(fn() => $isPusat ? null : $user->branch_id),

            // ----- Route section (visible only for pusat/edit context) -----
            Forms\Components\Section::make('Route & Expedition')
                ->description('Origin, destination, and shipping details (pusat only)')
                ->visible(fn() => $isPusat)
                ->schema([
                    Forms\Components\Select::make('origin_branch_id')
                        ->label('Origin Branch / Asal (Pusat)')
                        ->options(Branch::active()->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->default($mainBranch?->id)
                        ->columnSpan(1),

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

                    Forms\Components\DatePicker::make('eta')
                        ->label('ETA (Estimated Time of Arrival)')
                        ->nullable()
                        ->columnSpan(1),

                    Forms\Components\Select::make('shipment_status')
                        ->label('Shipment Status')
                        ->options([
                            'at_transportir_warehouse'  => 'Masih di Gudang Transportir',
                            'delivered_to_destination'  => 'Terkirim',
                        ])
                        ->nullable()
                        ->visible(fn(Forms\Get $get) => in_array($get('status'), ['in_transit', 'on_transportir']))
                        ->live()
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
        $user = auth()->user();
        $isPusat = $user->isOwnerPusat() || $user->isRegionalLeader();

        return $table
            ->modifyQueryUsing(function (Builder $query) use ($user, $isPusat) {
                $query->with(['originBranch', 'destinationBranch', 'expedition']);

                if (! $isPusat && $user->branch_id) {
                    // Regular branch: see DOs where they are the destination (incoming stock request)
                    $query->where('destination_branch_id', $user->branch_id);
                }
            })
            ->columns([
                Tables\Columns\TextColumn::make('do_number')
                    ->label('DO Number')
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

                Tables\Columns\IconColumn::make('receipt_path')
                    ->label('Receipt')
                    ->boolean()
                    ->trueIcon('heroicon-o-document-text')
                    ->falseIcon('heroicon-o-x-mark')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->toggleable(),
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
                // Download receipt
                Tables\Actions\Action::make('download_receipt')
                    ->label('Receipt')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('success')
                    ->visible(fn(DeliveryOrder $record) => $record->receipt_path)
                    ->url(fn(DeliveryOrder $record) => asset('storage/' . $record->receipt_path))
                    ->openUrlInNewTab(),

                // Submit (Branch)
                Tables\Actions\Action::make('submit')
                    ->label('Submit')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(
                        fn(DeliveryOrder $record) =>
                        $record->status === 'draft'
                    )
                    ->action(
                        fn(DeliveryOrder $record) =>
                        $record->update(['status' => 'pending_approval'])
                    )
                    ->requiresConfirmation()
                    ->modalHeading('Submit for Approval?')
                    ->modalDescription('This will send the DO to the central office for approval.'),

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
                    ->modalHeading('Approve this Delivery Order?'),

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
                    ->action(
                        fn(DeliveryOrder $record) =>
                        $record->update(['status' => 'in_transit'])
                    )
                    ->requiresConfirmation()
                    ->modalHeading('Mark as In Transit?'),

                // On Transportir (HQ)
                Tables\Actions\Action::make('mark_on_transportir')
                    ->label('On Transportir')
                    ->icon('heroicon-o-truck')
                    ->color('purple')
                    ->visible(
                        fn(DeliveryOrder $record) =>
                        $record->status === 'in_transit' &&
                            auth()->user()?->canApproveOrders()
                    )
                    ->action(
                        fn(DeliveryOrder $record) =>
                        $record->update(['status' => 'on_transportir'])
                    )
                    ->requiresConfirmation()
                    ->modalHeading('Mark as On Transportir?')
                    ->modalDescription('Confirm the shipment is now with the transportir/expedition.'),

                // Upload Receipt (HQ - after on_transportir)
                Tables\Actions\Action::make('upload_receipt')
                    ->label('Upload Receipt')
                    ->icon('heroicon-o-document-arrow-up')
                    ->color('success')
                    ->visible(
                        fn(DeliveryOrder $record) =>
                        in_array($record->status, ['on_transportir', 'delivered']) &&
                            auth()->user()?->canApproveOrders()
                    )
                    ->form([
                        Forms\Components\FileUpload::make('receipt_path')
                            ->label('Receipt / Proof of Delivery')
                            ->directory('do-receipts')
                            ->acceptedFileTypes(['image/*', 'application/pdf'])
                            ->maxSize(2048)
                            ->required(),
                    ])
                    ->action(
                        fn(DeliveryOrder $record, array $data) =>
                        $record->update(['receipt_path' => $data['receipt_path']])
                    )
                    ->modalHeading('Upload DO Receipt'),

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
                    ->modalDescription('Mark this DO as arrived at the destination branch.'),

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
                    ->action(
                        fn(DeliveryOrder $record) =>
                        $record->update(['status' => 'cancelled'])
                    )
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

    /** Pusat cannot create DOs — only approve/manage. */
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
            'index'  => Pages\ListDeliveryOrders::route('/'),
            'create' => Pages\CreateDeliveryOrder::route('/create'),
            'edit'   => Pages\EditDeliveryOrder::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canAccessPanel(app(\Filament\Panel::class));
    }
}
