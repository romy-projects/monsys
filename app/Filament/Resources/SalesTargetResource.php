<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SalesTargetResource\Pages;
use App\Models\Branch;
use App\Models\SalesTarget;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SalesTargetResource extends Resource
{
    protected static ?string $model = SalesTarget::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'cylinder_type';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.sales_target');
    }

    public static function getModelLabel(): string
    {
        return 'Sales Target';
    }

    public static function form(Form $form): Form
    {
        $months = [
            1 => 'January', 2 => 'February', 3 => 'March',    4 => 'April',
            5 => 'May',      6 => 'June',     7 => 'July',     8 => 'August',
            9 => 'September',10 => 'October',11 => 'November',12 => 'December',
        ];

        $years = collect(range(now()->year - 1, now()->year + 2))
            ->mapWithKeys(fn ($y) => [$y => (string) $y])
            ->all();

        return $form->schema([
            Forms\Components\Section::make('Target Details')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch')
                        ->options(Branch::active()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->columnSpan(2),

                    Forms\Components\Select::make('year')
                        ->options($years)
                        ->default(now()->year)
                        ->required(),

                    Forms\Components\Select::make('month')
                        ->options($months)
                        ->default(now()->month)
                        ->required(),

                    Forms\Components\Select::make('cylinder_type')
                        ->options([
                            '3kg'   => '3 kg',
                            '5.5kg' => '5.5 kg',
                            '12kg'  => '12 kg',
                            '50kg'  => '50 kg',
                        ])
                        ->required(),

                    Forms\Components\TextInput::make('target_qty')
                        ->label('Target Qty (pcs)')
                        ->numeric()
                        ->minValue(0)
                        ->required(),

                    Forms\Components\TextInput::make('target_revenue')
                        ->label('Target Revenue (Rp)')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('Rp')
                        ->required(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        $months = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
            5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
            9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
        ];

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold),

                Tables\Columns\TextColumn::make('year')
                    ->sortable(),

                Tables\Columns\TextColumn::make('month')
                    ->formatStateUsing(fn ($state) => $months[$state] ?? $state)
                    ->sortable(),

                Tables\Columns\TextColumn::make('cylinder_type')
                    ->label('Type')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('target_qty')
                    ->label('Target Qty')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('target_revenue')
                    ->label('Target Revenue')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format($state, 0, ',', '.'))
                    ->sortable(),
            ])
            ->defaultSort('year', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(Branch::active()->orderBy('name')->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('cylinder_type')
                    ->options(['3kg' => '3kg', '5.5kg' => '5.5kg', '12kg' => '12kg', '50kg' => '50kg']),
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
            ->modifyQueryUsing(function ($query) {
                $user = auth()->user();

                if (! $user->isOwnerPusat() && ! $user->isRegionalLeader()) {
                    $query->where('branch_id', $user->branch_id);
                }
            });
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSalesTargets::route('/'),
            'create' => Pages\CreateSalesTarget::route('/create'),
            'edit'   => Pages\EditSalesTarget::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isOwnerPusat() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->isOwnerPusat() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isOwnerPusat() ?? false;
    }
}
