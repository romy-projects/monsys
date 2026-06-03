<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LpgPriceResource\Pages;
use App\Models\Branch;
use App\Models\LpgPrice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LpgPriceResource extends Resource
{
    protected static ?string $model = LpgPrice::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return __('nav.item.master_prices');
    }

    public static function getModelLabel(): string
    {
        return 'LPG Price';
    }

    public static function form(Form $form): Form
    {
        $isPusat = auth()->user()?->isOwnerPusat();

        return $form->schema([
            Forms\Components\Section::make('Price Configuration')
                ->description('Set purchase (HPP) and selling price for each cylinder type')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch (Optional)')
                        ->options(Branch::active()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->visible(fn() => $isPusat)
                        ->helperText('Leave empty for global price. Select a branch for branch-specific override.')
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

                    Forms\Components\DatePicker::make('effective_date')
                        ->label('Effective Date / Berlaku Mulai')
                        ->required()
                        ->default(today())
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('purchase_price')
                        ->label('Purchase Price / HPP (per tabung)')
                        ->numeric()
                        ->required()
                        ->prefix('Rp')
                        ->minValue(0)
                        ->helperText('Harga beli / cost of goods per cylinder')
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('selling_price')
                        ->label('Standard Selling Price / Harga Jual Standar')
                        ->numeric()
                        ->required()
                        ->prefix('Rp')
                        ->minValue(0)
                        ->helperText('Harga jual standar yang disarankan ke cabang')
                        ->columnSpan(1),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->placeholder('Global')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('cylinder_type')
                    ->label('Type')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('purchase_price')
                    ->label('Purchase Price (HPP)')
                    ->formatStateUsing(fn($state) => 'Rp ' . number_format($state, 0, ',', '.'))
                    ->color('danger'),

                Tables\Columns\TextColumn::make('selling_price')
                    ->label('Selling Price')
                    ->formatStateUsing(fn($state) => 'Rp ' . number_format($state, 0, ',', '.'))
                    ->color('success'),

                Tables\Columns\TextColumn::make('margin')
                    ->label('Margin')
                    ->getStateUsing(
                        fn(LpgPrice $record): string => $record->purchase_price > 0
                            ? round((($record->selling_price - $record->purchase_price) / $record->selling_price) * 100, 1) . '%'
                            : '—'
                    )
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('effective_date')
                    ->label('Effective From')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Set By')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('cylinder_type')
                    ->options([
                        '3kg'   => '3 kg',
                        '5.5kg' => '5.5 kg',
                        '12kg'  => '12 kg',
                        '50kg'  => '50 kg',
                    ]),

                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(Branch::active()->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn() => auth()->user()?->isOwnerPusat()),
            ])
            ->modifyQueryUsing(function ($query) {
                $user = auth()->user();
                if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
                    $query->where(
                        fn($q) =>
                        $q->where('branch_id', $user->branch_id)
                            ->orWhereNull('branch_id')
                    );
                }
            })
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('effective_date', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListLpgPrices::route('/'),
            'create' => Pages\CreateLpgPrice::route('/create'),
            'edit'   => Pages\EditLpgPrice::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['owner_pusat', 'regional_leader']);
    }
}
