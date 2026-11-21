<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransportirRateResource\Pages;
use App\Models\Transportir;
use App\Models\TransportirRate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * Transportir haulage rates per cylinder type (BALI-10).
 *
 * Rates are effective-dated, so changing a tariff never rewrites the cost of an
 * earlier PO/LO — a new row is added instead.
 */
class TransportirRateResource extends Resource
{
    protected static ?string $model = TransportirRate::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return __('nav.item.master_transportir_rates');
    }

    public static function getModelLabel(): string
    {
        return 'Transportir Rate';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Transportir Rates';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Transportir Rate / Tarif Transportir')
                ->description('Fixed haulage rate per cylinder. Add a new row when the tariff changes.')
                ->schema([
                    Forms\Components\Select::make('transportir_id')
                        ->label('Transportir')
                        ->options(Transportir::where('status', 'active')->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
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

                    Forms\Components\TextInput::make('rate')
                        ->label('Rate per Cylinder / Tarif per Tabung')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->prefix('Rp')
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('effective_date')
                        ->label('Effective Date / Berlaku Mulai')
                        ->required()
                        ->default(today())
                        // One rate per transportir + cylinder type per effective date.
                        // Compared with whereDate() because the column is stored as a
                        // datetime; Rule::unique() would miss it (value vs. 'Y-m-d 00:00:00').
                        ->rule(fn (Get $get, $record) => function (string $attribute, $value, \Closure $fail) use ($get, $record): void {
                            $date = $value ? Carbon::parse($value)->toDateString() : null;

                            if (! $date || ! $get('transportir_id') || ! $get('cylinder_type')) {
                                return;
                            }

                            $exists = TransportirRate::where('transportir_id', $get('transportir_id'))
                                ->where('cylinder_type', $get('cylinder_type'))
                                ->whereDate('effective_date', $date)
                                ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                                ->exists();

                            if ($exists) {
                                $fail('A rate for this transportir and cylinder type already exists on that effective date.');
                            }
                        })
                        ->columnSpan(1),

                    Forms\Components\Hidden::make('created_by')
                        ->default(fn () => auth()->id()),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['transportir', 'createdBy']))
            ->columns([
                Tables\Columns\TextColumn::make('transportir.name')
                    ->label('Transportir')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('cylinder_type')
                    ->label('Type')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('rate')
                    ->label('Rate / Tabung')
                    ->formatStateUsing(fn ($state) => 'Rp '.number_format((float) $state, 0, ',', '.'))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('effective_date')
                    ->label('Effective Date')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('createdBy.name')
                    ->label('Added By')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('transportir_id')
                    ->label('Transportir')
                    ->relationship('transportir', 'name')
                    ->searchable(),

                Tables\Filters\SelectFilter::make('cylinder_type')
                    ->label('Cylinder Type')
                    ->options([
                        '3kg' => '3 kg',
                        '5.5kg' => '5.5 kg',
                        '12kg' => '12 kg',
                        '50kg' => '50 kg',
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
            'index' => Pages\ListTransportirRates::route('/'),
            'create' => Pages\CreateTransportirRate::route('/create'),
            'edit' => Pages\EditTransportirRate::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return static::can('viewAny');
    }
}
