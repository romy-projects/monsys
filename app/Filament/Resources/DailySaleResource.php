<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailySaleResource\Pages;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DailySale;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
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

        return $form->schema([
            Forms\Components\Section::make('Sale Information')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch / Cabang')
                        ->options(
                            $user?->isOwnerPusat() || $user?->isRegionalLeader()
                                ? Branch::active()->pluck('name', 'id')
                                : Branch::where('id', $user?->branch_id)->pluck('name', 'id')
                        )
                        ->default($user?->branch_id)
                        ->required()
                        ->searchable()
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('sale_date')
                        ->label('Sale Date / Tanggal')
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

                    Forms\Components\Select::make('buyer_type')
                        ->label('Buyer Type / Jenis Pembeli')
                        ->options([
                            'retail'    => 'Retail / Eceran',
                            'agent'     => 'Agent / Agen',
                            'corporate' => 'Corporate / Perusahaan',
                        ])
                        ->required()
                        ->default('retail')
                        ->columnSpan(1),

                    Forms\Components\Select::make('customer_id')
                        ->label('Customer (Optional)')
                        ->options(function () use ($user) {
                            $query = Customer::active()->orderBy('name');

                            if (! $user?->isOwnerPusat() && ! $user?->isRegionalLeader()) {
                                $query->where('branch_id', $user?->branch_id);
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

                    Forms\Components\TextInput::make('selling_price')
                        ->label('Selling Price per Unit / Harga Jual')
                        ->numeric()
                        ->required()
                        ->prefix('Rp')
                        ->minValue(0)
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
            ->modifyQueryUsing(fn ($query) =>
                $query->with(['branch', 'createdBy'])
                    ->when(
                        ! $user?->isOwnerPusat() && ! $user?->isRegionalLeader(),
                        fn ($q) => $q->where('branch_id', $user?->branch_id)
                    )
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
                        'retail'    => 'gray',
                        'agent'     => 'warning',
                        'corporate' => 'primary',
                        default     => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'retail'    => 'Retail',
                        'agent'     => 'Agent',
                        'corporate' => 'Corporate',
                        default     => $state,
                    }),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Qty')
                    ->formatStateUsing(fn ($state) => number_format($state) . ' pcs')
                    ->sortable(),

                Tables\Columns\TextColumn::make('selling_price')
                    ->label('Price/Unit')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format($state, 0, ',', '.')),

                Tables\Columns\TextColumn::make('total_revenue')
                    ->label('Total Revenue')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format($state, 0, ',', '.'))
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold)
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
                    ->options(Branch::active()->pluck('name', 'id'))
                    ->visible(fn () => $user?->isOwnerPusat() || $user?->isRegionalLeader()),

                Tables\Filters\SelectFilter::make('cylinder_type')
                    ->options([
                        '3kg'   => '3 kg',
                        '5.5kg' => '5.5 kg',
                        '12kg'  => '12 kg',
                        '50kg'  => '50 kg',
                    ]),

                Tables\Filters\SelectFilter::make('buyer_type')
                    ->options([
                        'retail'    => 'Retail',
                        'agent'     => 'Agent',
                        'corporate' => 'Corporate',
                    ]),

                Tables\Filters\Filter::make('sale_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'],  fn ($q) => $q->whereDate('sale_date', '>=', $data['from']))
                        ->when($data['until'], fn ($q) => $q->whereDate('sale_date', '<=', $data['until']))
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDailySales::route('/'),
            'create' => Pages\CreateDailySale::route('/create'),
            'edit'   => Pages\EditDailySale::route('/{record}/edit'),
        ];
    }
}
