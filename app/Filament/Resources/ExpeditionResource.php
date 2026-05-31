<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExpeditionResource\Pages;
use App\Models\Expedition;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ExpeditionResource extends Resource
{
    protected static ?string $model = Expedition::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('nav.item.master_expeditions');
    }

    public static function getModelLabel(): string
    {
        return 'Expedition';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Expedition Information')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Company Name / Nama Ekspedisi')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('code')
                        ->label('Code / Kode')
                        ->placeholder('e.g. EXP-01')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(20)
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('contact_person')
                        ->label('Contact Person / PIC')
                        ->maxLength(255)
                        ->nullable()
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('phone')
                        ->label('Phone / Telepon')
                        ->tel()
                        ->nullable()
                        ->columnSpan(1),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'active'   => '✅ Active',
                            'inactive' => '⛔ Inactive',
                        ])
                        ->default('active')
                        ->required()
                        ->columnSpan(1),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold),

                Tables\Columns\TextColumn::make('contact_person')
                    ->label('PIC')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Phone')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('delivery_orders_count')
                    ->label('DOs')
                    ->counts('deliveryOrders')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'danger'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active'   => 'Active',
                        'inactive' => 'Inactive',
                    ]),
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
            'index'  => Pages\ListExpeditions::route('/'),
            'create' => Pages\CreateExpedition::route('/create'),
            'edit'   => Pages\EditExpedition::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->role, ['owner_pusat', 'regional_leader']);
    }
}
