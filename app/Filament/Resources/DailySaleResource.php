<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailySaleResource\Pages;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DailySale;
use App\Support\LpgPriceFormState;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;

class DailySaleResource extends Resource
{
    protected static ?string $model = DailySale::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('nav.item.sales_input');
    }

    public static function getModelLabel(): string
    {
        return 'Daily Sale';
    }

    public static function form(Form $form): Form
    {
        $user = auth()->user();
        $branchIds = $user?->accessibleBranchIds() ?? [];
        $branchQuery = Branch::active();
        $user?->applyAccessibleBranchScope($branchQuery, 'branches.id');
        $branchOptions = $branchQuery->pluck('name', 'id');
        $defaultBranchId = $user?->branch_id !== null && in_array((int) $user->branch_id, $branchIds, true)
            ? $user->branch_id
            : ($branchIds[0] ?? null);

        return $form->schema([
            Forms\Components\Section::make('Sale Information')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch / Cabang')
                        ->options($branchOptions)
                        ->default($defaultBranchId)
                        ->required()
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => LpgPriceFormState::sync($get, $set, 'selling_price', true))
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('sale_date')
                        ->label('Sale Date / Tanggal')
                        ->required()
                        ->default(today())
                        ->live()
                        ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => LpgPriceFormState::sync($get, $set, 'selling_price', true))
                        ->columnSpan(1),

                    Forms\Components\Select::make('cylinder_type')
                        ->label('Cylinder Type / Jenis Tabung')
                        ->options([
                            '3kg' => '3 kg',
                            '5.5kg' => '5.5 kg',
                            '12kg' => '12 kg',
                            '50kg' => '50 kg',
                        ])
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => LpgPriceFormState::sync($get, $set, 'selling_price', true))
                        ->columnSpan(1),

                    Forms\Components\Select::make('buyer_type')
                        ->label('Buyer Type / Jenis Pembeli')
                        ->options([
                            'retail' => 'Retail / Eceran',
                            'agent' => 'Agent / Agen',
                            'corporate' => 'Corporate / Perusahaan',
                        ])
                        ->required()
                        ->default('retail')
                        ->columnSpan(1),

                    Forms\Components\Select::make('customer_id')
                        ->label('Customer (Optional)')
                        ->options(function (Forms\Get $get) {
                            $query = Customer::active()->orderBy('name');
                            if (filled($get('branch_id'))) {
                                $query->where('branch_id', $get('branch_id'));
                            }

                            return $query->pluck('name', 'id');
                        })
                        ->searchable()
                        ->nullable()
                        ->placeholder('Walk-in / anonymous')
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('quantity')
                        ->label('Quantity Sold / Jumlah Terjual')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->suffix('pcs')
                        ->columnSpan(1),

                    Forms\Components\Hidden::make('lpg_price_id'),

                    Forms\Components\Hidden::make('purchase_price_at_sale'),

                    Forms\Components\Placeholder::make('lpg_price_reference')
                        ->label('LPG Price Reference')
                        ->content(fn (Forms\Get $get) => $get('lpg_price_id')
                            ? 'LPG Price #'.$get('lpg_price_id')
                            : 'Select branch and cylinder type to load the effective LPG Price.')
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('selling_price')
                        ->label('Selling Price per Unit / Harga Jual')
                        ->prefix('Rp')
                        ->disabled()
                        ->dehydrated()
                        ->helperText('Loaded from the effective LPG Price record.')
                        ->columnSpan(1),
                ])->columns(2),

            Forms\Components\Section::make('Notes')
                ->schema([
                    Forms\Components\Textarea::make('notes')
                        ->label('Notes / Catatan')
                        ->nullable()
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = auth()->user();

        return $table
            ->modifyQueryUsing(
                fn ($query) => $query->with(['branch', 'createdBy', 'lpgPrice'])
            )
            ->columns([
                Tables\Columns\TextColumn::make('sale_date')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('cylinder_type')
                    ->label('Type')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('buyer_type')
                    ->label('Buyer')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'retail' => 'gray',
                        'agent' => 'warning',
                        'corporate' => 'primary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'retail' => 'Retail',
                        'agent' => 'Agent',
                        'corporate' => 'Corporate',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Qty')
                    ->formatStateUsing(fn ($state) => number_format($state).' pcs')
                    ->sortable(),

                Tables\Columns\TextColumn::make('selling_price')
                    ->label('Price/Unit')
                    ->formatStateUsing(fn ($state) => 'Rp '.number_format($state, 0, ',', '.')),

                Tables\Columns\TextColumn::make('lpgPrice.id')
                    ->label('LPG Price #')
                    ->placeholder('Legacy')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('lpgPrice.effective_date')
                    ->label('Price Effective')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('total_revenue')
                    ->label('Total Revenue')
                    ->formatStateUsing(fn ($state) => 'Rp '.number_format($state, 0, ',', '.'))
                    ->weight(FontWeight::SemiBold)
                    ->color('success')
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Customer')
                    ->placeholder('Walk-in')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Recorded By')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(function () use ($user) {
                        $query = Branch::active();
                        $user?->applyAccessibleBranchScope($query, 'branches.id');

                        return $query->pluck('name', 'id');
                    })
                    ->visible(fn () => $user?->canAccessAllBranches() || count($user?->accessibleBranchIds() ?? []) > 1),

                Tables\Filters\SelectFilter::make('cylinder_type')
                    ->options([
                        '3kg' => '3 kg',
                        '5.5kg' => '5.5 kg',
                        '12kg' => '12 kg',
                        '50kg' => '50 kg',
                    ]),

                Tables\Filters\SelectFilter::make('buyer_type')
                    ->options([
                        'retail' => 'Retail',
                        'agent' => 'Agent',
                        'corporate' => 'Corporate',
                    ]),

                Tables\Filters\Filter::make('sale_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(
                        fn ($query, array $data) => $query
                            ->when($data['from'], fn ($q) => $q->whereDate('sale_date', '>=', $data['from']))
                            ->when($data['until'], fn ($q) => $q->whereDate('sale_date', '<=', $data['until']))
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn (DailySale $record) => static::can('update', $record))
                    ->authorize(fn (DailySale $record) => static::can('update', $record)),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (DailySale $record) => static::can('delete', $record))
                    ->authorize(fn (DailySale $record) => static::can('delete', $record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sale_date', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    /** Pusat/Regional can only monitor — cannot create sales. */
    public static function canCreate(): bool
    {
        return static::can('create');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailySales::route('/'),
            'create' => Pages\CreateDailySale::route('/create'),
            'edit' => Pages\EditDailySale::route('/{record}/edit'),
        ];
    }
}
