<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OperationalCostResource\Pages;
use App\Models\Branch;
use App\Models\OperationalCost;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;

class OperationalCostResource extends Resource
{
    protected static ?string $model = OperationalCost::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('nav.item.finance_logistics');
    }

    public static function getModelLabel(): string
    {
        return 'Operational Cost';
    }

    public static function form(Form $form): Form
    {
        $user = auth()->user();
        $branchIds = $user?->accessibleBranchIds() ?? [];
        $branchQuery = Branch::active()->orderBy('name');
        $user?->applyAccessibleBranchScope($branchQuery, 'branches.id');
        $defaultBranchId = $user?->branch_id !== null && in_array((int) $user->branch_id, $branchIds, true)
            ? $user->branch_id
            : ($branchIds[0] ?? null);

        return $form->schema([
            Forms\Components\Section::make('Cost Details')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch / Cabang')
                        ->options($branchQuery->pluck('name', 'id'))
                        ->default($defaultBranchId)
                        ->required()
                        ->searchable()
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('cost_date')
                        ->label('Date / Tanggal')
                        ->required()
                        ->default(today())
                        ->columnSpan(1),

                    Forms\Components\Select::make('cost_category')
                        ->label('Category / Kategori')
                        ->options([
                            'fuel' => '⛽ Fuel / BBM',
                            'salary' => '👷 Salary / Gaji',
                            'logistics' => '🚛 Logistics / Ongkir',
                            'levy' => '🏛️ Levy / Retribusi',
                            'other' => '📋 Other / Lain-lain',
                        ])
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('amount')
                        ->label('Amount / Jumlah')
                        ->numeric()
                        ->required()
                        ->prefix('Rp')
                        ->minValue(0)
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('description')
                        ->label('Description / Keterangan')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),
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
            ->modifyQueryUsing(fn ($query) => $query->with(['branch', 'createdBy']))
            ->columns([
                Tables\Columns\TextColumn::make('cost_date')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('cost_category')
                    ->label('Category')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'fuel' => 'warning',
                        'salary' => 'info',
                        'logistics' => 'primary',
                        'levy' => 'gray',
                        'other' => 'secondary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'fuel' => '⛽ Fuel',
                        'salary' => '👷 Salary',
                        'logistics' => '🚛 Logistics',
                        'levy' => '🏛️ Levy',
                        'other' => '📋 Other',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->searchable()
                    ->limit(40),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => 'Rp '.number_format($state, 0, ',', '.'))
                    ->weight(FontWeight::SemiBold)
                    ->color('danger')
                    ->sortable(),

                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Recorded By')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(function () use ($user) {
                        $query = Branch::active()->orderBy('name');
                        $user?->applyAccessibleBranchScope($query, 'branches.id');

                        return $query->pluck('name', 'id');
                    })
                    ->visible(fn () => $user?->canAccessAllBranches() || count($user?->accessibleBranchIds() ?? []) > 1
                    ),

                Tables\Filters\SelectFilter::make('cost_category')
                    ->label('Category')
                    ->options([
                        'fuel' => 'Fuel / BBM',
                        'salary' => 'Salary / Gaji',
                        'logistics' => 'Logistics / Ongkir',
                        'levy' => 'Levy / Retribusi',
                        'other' => 'Other',
                    ]),

                Tables\Filters\Filter::make('cost_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('until')->label('Until'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'], fn ($q) => $q->whereDate('cost_date', '>=', $data['from']))
                        ->when($data['until'], fn ($q) => $q->whereDate('cost_date', '<=', $data['until']))
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn ($record) => static::can('update', $record))
                    ->authorize(fn ($record) => static::can('update', $record)),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn ($record) => static::can('delete', $record))
                    ->authorize(fn ($record) => static::can('delete', $record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('cost_date', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOperationalCosts::route('/'),
            'create' => Pages\CreateOperationalCost::route('/create'),
            'edit' => Pages\EditOperationalCost::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('operational_costs.view') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasPermission('operational_costs.view') ?? false;
    }
}
