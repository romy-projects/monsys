<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Branch;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
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
        $isPusat = auth()->user()?->isOwnerPusat() || auth()->user()?->isRegionalLeader();

        return $form->schema([
            Forms\Components\Section::make('Customer Details')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch')
                        ->options(Branch::active()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->default(fn () => auth()->user()->branch_id)
                        ->disabled(! $isPusat)
                        ->dehydrated()
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2),

                    Forms\Components\Select::make('type')
                        ->options([
                            'retail'   => 'Retail / Eceran',
                            'agen'     => 'Agen / Distributor',
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
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: ! (auth()->user()?->isOwnerPusat() || auth()->user()?->isRegionalLeader())),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'agen'     => 'primary',
                        'industri' => 'warning',
                        default    => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'retail'   => 'Retail',
                        'agen'     => 'Agen',
                        'industri' => 'Industri',
                        default    => $state,
                    }),

                Tables\Columns\TextColumn::make('phone')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('credit_limit')
                    ->label('Credit Limit')
                    ->formatStateUsing(fn ($state) => $state > 0 ? 'Rp ' . number_format((float) $state, 0, ',', '.') : '—')
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
                        'retail'   => 'Retail',
                        'agen'     => 'Agen',
                        'industri' => 'Industri',
                    ]),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),

                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(Branch::active()->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn () => auth()->user()?->isOwnerPusat() || auth()->user()?->isRegionalLeader()),
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
            'index'  => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit'   => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isOwnerPusat() ?? false;
    }
}
