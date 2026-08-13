<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoadingOrderResource\Pages;
use App\Models\Branch;
use App\Models\DeliveryOrder;
use App\Models\Expedition;
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
        return 'Loading Order (LO)';
    }

    public static function getModelLabel(): string
    {
        return 'Loading Order';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Loading Orders';
    }

    public static function form(Form $form): Form
    {
        $user       = auth()->user();
        $isPusat    = $user->isOwnerPusat() || $user->isRegionalLeader();
        $mainBranch = Branch::mainBranch()->first();

        return $form->schema([
            Forms\Components\Section::make('Loading Order Details')
                ->description('Loading instruction for Transportir to take Tabung to Pertamina')
                ->schema([
                    Forms\Components\TextInput::make('do_number')
                        ->label('LO Number')
                        ->placeholder('e.g. LO2026-001')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(50)
                        ->default(function () {
                            $year  = date('Y');
                            $count = DeliveryOrder::loadingOrders()->whereYear('created_at', $year)->count() + 1;
                            return 'LO' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
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
                ->default('lo'),

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
                $query->loadingOrders()->with(['originBranch', 'destinationBranch', 'expedition', 'loadedBy']);

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
