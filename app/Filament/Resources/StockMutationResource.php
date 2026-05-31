<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockMutationResource\Pages;
use App\Models\Branch;
use App\Models\StockMutation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StockMutationResource extends Resource
{
    protected static ?string $model = StockMutation::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Stock Management';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('nav.item.stock_mutation');
    }

    public static function getModelLabel(): string
    {
        return 'Stock Mutation';
    }

    public static function form(Form $form): Form
    {
        $user = auth()->user();

        return $form->schema([
            Forms\Components\Section::make('Mutation Details')
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

                    Forms\Components\DatePicker::make('mutation_date')
                        ->label('Date / Tanggal')
                        ->required()
                        ->default(today())
                        ->columnSpan(1),

                    Forms\Components\Select::make('mutation_type')
                        ->label('Type / Jenis Mutasi')
                        ->options([
                            'in'         => '⬇️ Stock In / Masuk',
                            'out'        => '⬆️ Stock Out / Keluar',
                            'transfer'   => '↔️ Transfer Antar Cabang',
                            'adjustment' => '🔧 Adjustment / Koreksi',
                        ])
                        ->required()
                        ->reactive()
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

                    Forms\Components\TextInput::make('quantity')
                        ->label('Quantity / Jumlah')
                        ->numeric()
                        ->required()
                        ->suffix('pcs')
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('reference_no')
                        ->label('Reference No / No Referensi')
                        ->placeholder('e.g. DO2026-001 or PO-001')
                        ->nullable()
                        ->columnSpan(1),
                ])->columns(2),

            Forms\Components\Section::make('Transfer Details')
                ->visible(fn (Forms\Get $get) => $get('mutation_type') === 'transfer')
                ->schema([
                    Forms\Components\Select::make('source_branch_id')
                        ->label('Source Branch / Cabang Asal')
                        ->options(Branch::active()->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->columnSpan(1),

                    Forms\Components\Select::make('destination_branch_id')
                        ->label('Destination Branch / Cabang Tujuan')
                        ->options(Branch::active()->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
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
                $query->with(['branch', 'sourceBranch', 'destinationBranch', 'createdBy'])
                    ->when(
                        ! $user?->isOwnerPusat() && ! $user?->isRegionalLeader(),
                        fn ($q) => $q->where('branch_id', $user?->branch_id)
                    )
            )
            ->columns([
                Tables\Columns\TextColumn::make('mutation_date')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->searchable(),

                Tables\Columns\TextColumn::make('mutation_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'in'         => 'success',
                        'out'        => 'danger',
                        'transfer'   => 'info',
                        'adjustment' => 'warning',
                        default      => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'in'         => 'Stock In',
                        'out'        => 'Stock Out',
                        'transfer'   => 'Transfer',
                        'adjustment' => 'Adjustment',
                        default      => $state,
                    }),

                Tables\Columns\TextColumn::make('cylinder_type')
                    ->label('Type')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Quantity')
                    ->formatStateUsing(fn ($state) => number_format($state) . ' pcs')
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold),

                Tables\Columns\TextColumn::make('reference_no')
                    ->label('Reference')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sourceBranch.name')
                    ->label('From')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('destinationBranch.name')
                    ->label('To')
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

                Tables\Filters\SelectFilter::make('mutation_type')
                    ->label('Type')
                    ->options([
                        'in'         => 'Stock In',
                        'out'        => 'Stock Out',
                        'transfer'   => 'Transfer',
                        'adjustment' => 'Adjustment',
                    ]),

                Tables\Filters\SelectFilter::make('cylinder_type')
                    ->options([
                        '3kg'   => '3 kg',
                        '5.5kg' => '5.5 kg',
                        '12kg'  => '12 kg',
                        '50kg'  => '50 kg',
                    ]),

                Tables\Filters\Filter::make('mutation_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'],  fn ($q) => $q->whereDate('mutation_date', '>=', $data['from']))
                        ->when($data['until'], fn ($q) => $q->whereDate('mutation_date', '<=', $data['until']))
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
            ->defaultSort('mutation_date', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListStockMutations::route('/'),
            'create' => Pages\CreateStockMutation::route('/create'),
            'edit'   => Pages\EditStockMutation::route('/{record}/edit'),
        ];
    }
}
