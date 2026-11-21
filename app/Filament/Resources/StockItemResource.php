<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockItemResource\Pages;
use App\Models\Branch;
use App\Models\StockItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StockItemResource extends Resource
{
    protected static ?string $model = StockItem::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Stock Management';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('nav.item.stock_realtime');
    }

    public static function getModelLabel(): string
    {
        return 'Stock Item';
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
        $singleBranch = count($branchIds) === 1;

        return $form->schema([
            Forms\Components\Section::make('Stock Snapshot')
                ->description('Current stock level for this branch and cylinder type')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch / Cabang')
                        ->options($branchOptions)
                        ->default($defaultBranchId)
                        ->required()
                        ->searchable()
                        ->disabled($singleBranch)
                        ->dehydrated()
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
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('recorded_at')
                        ->label('Recorded At / Tanggal Catat')
                        ->required()
                        ->default(today())
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('qty_full')
                        ->label('Full Cylinders / Tabung Isi')
                        ->numeric()
                        ->required()
                        ->default(0)
                        ->minValue(0)
                        ->suffix('pcs')
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('qty_empty')
                        ->label('Empty Cylinders / Tabung Kosong')
                        ->numeric()
                        ->required()
                        ->default(0)
                        ->minValue(0)
                        ->suffix('pcs')
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('qty_damaged')
                        ->label('Damaged Cylinders / Tabung Rusak')
                        ->numeric()
                        ->required()
                        ->default(0)
                        ->minValue(0)
                        ->suffix('pcs')
                        ->columnSpan(1),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = auth()->user();

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('branch'))
            ->columns([
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::SemiBold),

                Tables\Columns\TextColumn::make('cylinder_type')
                    ->label('Type')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('qty_full')
                    ->label('Full (Isi)')
                    ->formatStateUsing(fn ($state) => number_format($state).' pcs')
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        $state < 20 => 'danger',
                        $state < 50 => 'warning',
                        default => 'success',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('qty_empty')
                    ->label('Empty (Kosong)')
                    ->formatStateUsing(fn ($state) => number_format($state).' pcs')
                    ->badge()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('qty_damaged')
                    ->label('Damaged (Rusak)')
                    ->formatStateUsing(fn ($state) => number_format($state).' pcs')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('recorded_at')
                    ->label('Recorded At')
                    ->date('d M Y')
                    ->sortable(),
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
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn (StockItem $record) => static::canEdit($record)),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (StockItem $record) => static::canDelete($record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn () => static::can('deleteAny')),
                ]),
            ])
            ->defaultSort('recorded_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockItems::route('/'),
            'create' => Pages\CreateStockItem::route('/create'),
            'edit' => Pages\EditStockItem::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return static::can('create');
    }

    public static function canEdit(Model $record): bool
    {
        return static::can('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::can('delete', $record);
    }
}
