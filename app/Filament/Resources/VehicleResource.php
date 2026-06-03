<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehicleResource\Pages;
use App\Models\Expedition;
use App\Models\Vehicle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'plate_number';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.vehicle');
    }

    public static function getModelLabel(): string
    {
        return 'Vehicle & Driver';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Vehicle')
                ->schema([
                    Forms\Components\Select::make('expedition_id')
                        ->label('Transportir / Expedition')
                        ->options(Expedition::where('status', 'active')->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->helperText('Select the expedition/transportir this vehicle belongs to'),

                    Forms\Components\TextInput::make('plate_number')
                        ->label('Plate Number')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(20)
                        ->placeholder('B 1234 XYZ'),

                    Forms\Components\Select::make('type')
                        ->options([
                            'pickup'     => 'Pickup',
                            'truck'      => 'Truck',
                            'motorcycle' => 'Motorcycle',
                            'other'      => 'Other',
                        ])
                        ->required()
                        ->default('pickup'),

                    Forms\Components\TextInput::make('capacity_kg')
                        ->label('Capacity (kg)')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('kg')
                        ->nullable(),

                    Forms\Components\Select::make('status')
                        ->options(['active' => 'Active', 'inactive' => 'Inactive'])
                        ->required()
                        ->default('active'),
                ])->columns(2),

            Forms\Components\Section::make('Driver')
                ->schema([
                    Forms\Components\TextInput::make('driver_name')
                        ->required()
                        ->maxLength(100),

                    Forms\Components\TextInput::make('driver_phone')
                        ->tel()
                        ->nullable()
                        ->maxLength(30),

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
                Tables\Columns\TextColumn::make('plate_number')
                    ->label('Plate')
                    ->searchable()
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold),

                Tables\Columns\TextColumn::make('expedition.name')
                    ->label('Transportir')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn($state) => match ($state) {
                        'truck'  => 'primary',
                        'pickup' => 'info',
                        default  => 'gray',
                    })
                    ->formatStateUsing(fn($state) => ucfirst($state)),

                Tables\Columns\TextColumn::make('driver_name')
                    ->label('Driver')
                    ->searchable(),

                Tables\Columns\TextColumn::make('driver_phone')
                    ->label('Phone')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('capacity_kg')
                    ->label('Capacity')
                    ->formatStateUsing(fn($state) => $state ? number_format((float) $state, 0) . ' kg' : '—')
                    ->alignRight()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn($state) => $state === 'active' ? 'success' : 'gray')
                    ->formatStateUsing(fn($state) => ucfirst($state)),
            ])
            ->defaultSort('plate_number')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'pickup'     => 'Pickup',
                        'truck'      => 'Truck',
                        'motorcycle' => 'Motorcycle',
                        'other'      => 'Other',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->options(['active' => 'Active', 'inactive' => 'Inactive']),

                Tables\Filters\SelectFilter::make('expedition_id')
                    ->label('Transportir')
                    ->options(Expedition::where('status', 'active')->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn() => auth()->user()?->isOwnerPusat() || auth()->user()?->isRegionalLeader()),
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
                    $query->whereHas(
                        'expedition',
                        fn($q) =>
                        $q->where('status', 'active')
                    );
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
            'index'  => Pages\ListVehicles::route('/'),
            'create' => Pages\CreateVehicle::route('/create'),
            'edit'   => Pages\EditVehicle::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user?->isOwnerPusat() || $user?->isRegionalLeader() || $user?->isOwnerCabang();
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isOwnerPusat() ?? false;
    }
}
