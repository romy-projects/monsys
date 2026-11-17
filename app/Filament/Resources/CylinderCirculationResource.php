<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CylinderCirculationResource\Pages;
use App\Models\Branch;
use App\Models\CylinderCirculation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CylinderCirculationResource extends Resource
{
    protected static ?string $model = CylinderCirculation::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Tabung Sirkulasi';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'so_number';

    public static function getNavigationLabel(): string
    {
        return 'Sirkulasi Tabung';
    }

    public static function getModelLabel(): string
    {
        return 'Cylinder Circulation';
    }

    public static function form(Form $form): Form
    {
        $user = auth()->user();
        $isPusat = $user?->isOwnerPusat() ?? false;

        return $form->schema([
            Forms\Components\Section::make('Transaction Details')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch')
                        ->options(
                            $isPusat
                                ? Branch::where('status', 'active')->orderBy('name')->pluck('name', 'id')
                                : Branch::whereKey($user->branch_id)->pluck('name', 'id')
                        )
                        ->searchable()
                        ->required()
                        ->default(fn() => $isPusat ? null : $user->branch_id)
                        ->disabled(fn() => ! $isPusat)
                        ->dehydrated()
                        ->columnSpan(2),

                    Forms\Components\DatePicker::make('transaction_date')
                        ->label('Transaction Date')
                        ->required()
                        ->default(today()),

                    Forms\Components\TextInput::make('so_number')
                        ->label('SO/PO Reference')
                        ->nullable()
                        ->maxLength(100)
                        ->placeholder('e.g. SO-2026-001'),

                    Forms\Components\Select::make('transaction_type')
                        ->label('Transaction Type')
                        ->options([
                            'kirim'          => 'Kirim (Pengiriman)',
                            'bongkar_kosong' => 'Bongkar Kosong',
                            'pembelian'      => 'Pembelian Tabung Baru',
                            'penyesuaian'    => 'Penyesuaian',
                        ])
                        ->required()
                        ->live(),

                    Forms\Components\Textarea::make('description')
                        ->label('Description')
                        ->nullable()
                        ->rows(2)
                        ->columnSpan(2),

                    Forms\Components\Select::make('cylinder_type')
                        ->label('Cylinder Type')
                        ->options([
                            '3kg'  => '3 kg',
                            '5.5kg' => '5.5 kg',
                            '12kg' => '12 kg',
                            '50kg' => '50 kg',
                        ])
                        ->required(),

                    Forms\Components\Select::make('direction')
                        ->label('Direction')
                        ->options([
                            'debit' => 'Debit (Masuk)',
                            'kredit' => 'Kredit (Keluar)',
                        ])
                        ->required()
                        ->helperText('Auto-set based on transaction type, but can be overridden.'),

                    Forms\Components\TextInput::make('quantity')
                        ->label('Quantity')
                        ->numeric()
                        ->minValue(1)
                        ->required(),

                    Forms\Components\TextInput::make('container_no')
                        ->label('Container No')
                        ->nullable()
                        ->maxLength(100),

                    Forms\Components\TextInput::make('handled_by')
                        ->label('Handled By')
                        ->nullable()
                        ->maxLength(200)
                        ->placeholder('Driver/officer name'),

                    Forms\Components\Textarea::make('notes')
                        ->label('Notes')
                        ->nullable()
                        ->rows(2)
                        ->columnSpan(2),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = auth()->user();
        $isPusat = $user?->isOwnerPusat();

        return $table
            ->modifyQueryUsing(function (Builder $query) use ($user, $isPusat) {
                if (! $isPusat && $user) {
                    $query->where('branch_id', $user->branch_id);
                }
            })
            ->columns([
                Tables\Columns\TextColumn::make('transaction_date')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('so_number')
                    ->label('SO/PO #')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('transaction_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'kirim'          => 'info',
                        'bongkar_kosong' => 'warning',
                        'pembelian'      => 'success',
                        'penyesuaian'    => 'gray',
                    })
                    ->formatStateUsing(fn($state) => ucfirst(str_replace('_', ' ', $state))),

                Tables\Columns\TextColumn::make('cylinder_type')
                    ->label('Type')
                    ->badge(),

                Tables\Columns\TextColumn::make('direction')
                    ->label('Dir')
                    ->badge()
                    ->color(fn(string $state): string => $state === 'debit' ? 'success' : 'danger')
                    ->formatStateUsing(fn($state) => $state === 'debit' ? 'IN' : 'OUT'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Qty')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(50)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('handled_by')
                    ->label('Handled By')
                    ->toggleable()
                    ->placeholder('—'),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(Branch::where('status', 'active')->orderBy('name')->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('transaction_type')
                    ->options([
                        'kirim'          => 'Kirim',
                        'bongkar_kosong' => 'Bongkar Kosong',
                        'pembelian'      => 'Pembelian',
                        'penyesuaian'    => 'Penyesuaian',
                    ]),

                Tables\Filters\SelectFilter::make('cylinder_type')
                    ->options([
                        '3kg'  => '3 kg',
                        '5.5kg' => '5.5 kg',
                        '12kg' => '12 kg',
                        '50kg' => '50 kg',
                    ]),

                Tables\Filters\SelectFilter::make('direction')
                    ->options([
                        'debit' => 'Debit (IN)',
                        'kredit' => 'Kredit (OUT)',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn (CylinderCirculation $record) => static::canEdit($record)),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (CylinderCirculation $record) => static::canDelete($record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->using(fn (Collection $records) => $records
                            ->whereNull('purchase_order_id')
                            ->each(fn (CylinderCirculation $record) => $record->delete())),
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
            'index'  => Pages\ListCylinderCirculations::route('/'),
            'create' => Pages\CreateCylinderCirculation::route('/create'),
            'edit'   => Pages\EditCylinderCirculation::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Tabung Sirkulasi';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        return $user->isOwnerPusat();
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user?->isOwnerPusat() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $record->purchase_order_id === null && ($user?->isOwnerPusat() ?? false);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit($record);
    }
}
