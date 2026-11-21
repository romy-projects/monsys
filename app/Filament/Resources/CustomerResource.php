<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Branch;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.customer');
    }

    public static function getModelLabel(): string
    {
        return 'Customer';
    }

    public static function form(Form $form): Form
    {
        $user = auth()->user();
        $branchIds = $user?->accessibleBranchIds() ?? [];
        $branchQuery = Branch::active()->orderBy('name');
        $user?->applyAccessibleBranchScope($branchQuery, 'branches.id');
        $branchOptions = $branchQuery->pluck('name', 'id');
        $defaultBranchId = $user?->branch_id !== null && in_array((int) $user->branch_id, $branchIds, true)
            ? $user->branch_id
            : ($branchIds[0] ?? null);
        $singleBranch = count($branchIds) === 1;

        return $form->schema([
            Forms\Components\Section::make('Customer Details')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch')
                        ->options($branchOptions)
                        ->searchable()
                        ->required()
                        ->default($defaultBranchId)
                        ->disabled($singleBranch)
                        ->dehydrated()
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),

                    Forms\Components\Select::make('type')
                        ->options([
                            'retail' => 'Retail / Eceran',
                            'agen' => 'Agen / Distributor',
                            'industri' => 'Industri / Perusahaan',
                        ])
                        ->required()
                        ->default('retail'),

                    Forms\Components\TextInput::make('phone')
                        ->tel()
                        ->nullable()
                        ->maxLength(30),

                    Forms\Components\TextInput::make('credit_limit')
                        ->label('Credit Limit (Rp)')
                        ->numeric()
                        ->prefix('Rp')
                        ->minValue(0)
                        ->default(0),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),

                    Forms\Components\Textarea::make('address')
                        ->nullable()
                        ->rows(2)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('notes')
                        ->nullable()
                        ->rows(2)
                        ->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::SemiBold),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: ! (auth()->user()?->canAccessAllBranches() || count(auth()->user()?->accessibleBranchIds() ?? []) > 1)),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'agen' => 'primary',
                        'industri' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'retail' => 'Retail',
                        'agen' => 'Agen',
                        'industri' => 'Industri',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('phone')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('credit_limit')
                    ->label('Credit Limit')
                    ->formatStateUsing(fn ($state) => $state > 0 ? 'Rp '.number_format((float) $state, 0, ',', '.') : '—')
                    ->alignRight(),

                Tables\Columns\TextColumn::make('dailySales_count')
                    ->label('Sales')
                    ->counts('dailySales')
                    ->alignRight()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->alignCenter(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'retail' => 'Retail',
                        'agen' => 'Agen',
                        'industri' => 'Industri',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),

                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(function () {
                        $query = Branch::active()->orderBy('name');
                        auth()->user()?->applyAccessibleBranchScope($query, 'branches.id');

                        return $query->pluck('name', 'id');
                    })
                    ->visible(fn () => auth()->user()?->canAccessAllBranches() || count(auth()->user()?->accessibleBranchIds() ?? []) > 1),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return static::can('viewAny');
    }

    public static function canDelete(Model $record): bool
    {
        return static::can('delete', $record);
    }
}
