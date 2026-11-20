<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseOrderResource\Pages;
use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\Expedition;
use App\Models\Transportir;
use App\Models\TransportirRate;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
        $isPusat    = $user->isOwnerPusat();
        $canManageRoute = $isPusat || $user->isRegionalLeader();
        $mainBranch = Branch::mainBranch()->first();

        return $form->schema([
            Forms\Components\Section::make('Purchase Order Details')
                ->description('Request Tabung from Main Branch')
                ->schema([
                    // Allocated server-side in handleRecordCreation() — the old
                    // count()+1 default collided under concurrent creates (T11-09).
                    Forms\Components\TextInput::make('do_number')
                        ->label('PO Number')
                        ->placeholder('Auto: PO2026-001')
                        ->nullable()
                        ->unique(ignoreRecord: true)
                        ->maxLength(50)
                        ->helperText('Leave blank to allocate the next PO number automatically.')
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
                ->default(fn() => $isPusat ? null : $user->branch_id)
                ->visible(fn() => ! $canManageRoute),

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
                        ->disabled()
                        ->dehydrated()
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('eta')
                        ->label('ETA (Estimated Time of Arrival)')
                        ->nullable()
                        ->columnSpan(1),
                ])->columns(2),

            // ----- Route & Expedition (pusat only) -----
            Forms\Components\Section::make('Route & Expedition')
                ->description('Origin, destination, and shipping details')
                ->visible(fn() => $canManageRoute)
                ->schema([
                    Forms\Components\Select::make('destination_branch_id')
                        ->label('Requesting Branch / Cabang Pemohon')
                        ->options(
                            Branch::active()
                                ->when($mainBranch, fn (Builder $query) => $query->where('id', '!=', $mainBranch->id))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                        )
                        ->searchable()
                        ->required()
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->columnSpan(1),

                    Forms\Components\Select::make('transportir_id')
                        ->label('Transportir')
                        ->options(Transportir::where('status', 'active')->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->columnSpan(1),

                    Forms\Components\Select::make('expedition_id')
                        ->label('Expedition / Ekspedisi')
                        ->options(Expedition::where('status', 'active')->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->columnSpan(1),

                    // Phase 9: Vehicle & Driver input hidden (not mandatory on PO).
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
                        ->columnSpan(1),
                ])->columns(2),

            // ----- Transportir rate (BALI-10) -----
            Forms\Components\Section::make('Transporter Rate & Cost')
                ->description('Fixed haulage rate for this PO. Read-only — maintained in Master Data → Transportir Rates.')
                ->visible(fn (Get $get) => filled($get('transportir_id')))
                ->schema([
                    Forms\Components\Placeholder::make('transportir_rate_display')
                        ->label('Rate per Cylinder / Tarif per Tabung')
                        ->content(fn (Get $get) => self::money(
                            self::transportirRate($get('transportir_id'), $get('cylinder_type'), $get('order_date'))
                        )),

                    Forms\Components\Placeholder::make('transportir_cost_display')
                        ->label('Transportir Cost (rate × qty)')
                        ->content(fn (Get $get) => self::money(
                            self::transportirRate($get('transportir_id'), $get('cylinder_type'), $get('order_date'))
                            * (int) ($get('quantity_ordered') ?? 0)
                        )),
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
                $query->purchaseOrders()->with(['originBranch', 'destinationBranch', 'expedition', 'requestedBy']);

                if (! $isPusat) {
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

                Tables\Columns\TextColumn::make('requestedBy.name')
                    ->label('Requested By')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('cylinder_type')
                    ->label('Type')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('quantity_ordered')
                    ->label('Qty')
                    ->formatStateUsing(fn($state) => number_format($state) . ' pcs'),

                Tables\Columns\TextColumn::make('transportir_id')
                    ->label('Transportir Rate')
                    ->formatStateUsing(fn ($state, DeliveryOrder $record) => filled($state)
                        ? self::money(self::transportirRate((int) $state, $record->cylinder_type, $record->order_date))
                        : '—')
                    ->alignEnd()
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

                // D10: proof of delivery travels with the PO now that DO is retired.
                Tables\Columns\TextColumn::make('receipt_path')
                    ->label('Bukti Terima')
                    ->formatStateUsing(fn ($state): string => filled($state) ? 'Lihat' : '—')
                    ->url(fn (DeliveryOrder $record): ?string => filled($record->receipt_path)
                        ? asset('storage/'.$record->receipt_path)
                        : null)
                    ->openUrlInNewTab()
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
                            && auth()->user()?->canCreatePurchaseOrder()
                            && auth()->user()?->branch_id === $record->destination_branch_id
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

                // Bukti terima / proof of delivery (D10) — HQ approvers or the receiving branch.
                Tables\Actions\Action::make('upload_receipt')
                    ->label('Upload Bukti Terima')
                    ->icon('heroicon-o-document-arrow-up')
                    ->color('success')
                    ->visible(
                        fn(DeliveryOrder $record) =>
                        in_array($record->status, ['in_transit', 'delivered'], true) &&
                            (auth()->user()?->canApproveOrders() ||
                                ((int) auth()->user()?->branch_id === (int) $record->destination_branch_id))
                    )
                    ->form([
                        Forms\Components\FileUpload::make('receipt_path')
                            ->label('Receipt / Proof of Delivery')
                            ->directory('po-receipts')
                            ->acceptedFileTypes(['image/*', 'application/pdf'])
                            ->maxSize(2048)
                            ->required(),
                    ])
                    ->action(fn(DeliveryOrder $record, array $data) => $record->update(['receipt_path' => $data['receipt_path']]))
                    ->modalHeading('Upload Bukti Terima (PO)')
                    ->modalDescription('JPG / PNG / PDF, maksimal 2 MB.'),

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
                    ->visible(fn(DeliveryOrder $record) => static::canEdit($record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('order_date', 'desc');
    }

    /** Pusat and branch users can create POs; regional users can only manage them. */
    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user->isOwnerPusat() || $user->canCreatePurchaseOrder();
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $record->document_type === 'po' &&
            $record->status === 'draft' &&
            ($user?->isOwnerPusat() ||
                ($user?->canCreatePurchaseOrder() && $record->destination_branch_id === $user->branch_id));
    }

    public static function canDelete(Model $record): bool
    {
        $user = auth()->user();

        return $record->document_type === 'po' &&
            $record->status === 'draft' &&
            ($user?->isOwnerPusat() ||
                ($user?->canCreatePurchaseOrder() && $record->destination_branch_id === $user->branch_id));
    }

    /**
     * Rate (Rp per cylinder) for a transportir + cylinder type effective on the
     * PO order date, or 0 when no rate is configured yet (BALI-10).
     */
    private static function transportirRate(?int $transportirId, ?string $cylinderType, mixed $date): float
    {
        if (! $transportirId || ! $cylinderType) {
            return 0.0;
        }

        $on = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : ($date ?: today()->toDateString());

        return TransportirRate::amountAtDate((int) $transportirId, $cylinderType, $on);
    }

    private static function money(float $amount): string
    {
        return 'Rp ' . number_format($amount, 0, ',', '.');
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
