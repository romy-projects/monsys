<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PayableResource\Pages;
use App\Models\Expedition;
use App\Models\Payable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PayableResource extends Resource
{
    protected static ?string $model = Payable::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'invoice_number';

    public static function getNavigationLabel(): string
    {
        return 'Utang Transportir';
    }

    public static function getModelLabel(): string
    {
        return 'Payable';
    }

    public static function form(Form $form): Form
    {
        $user = auth()->user();
        $isPusat = $user->isOwnerPusat() || $user->isRegionalLeader();

        return $form->schema([
            Forms\Components\Section::make('Payable Details')
                ->schema([
                    Forms\Components\Select::make('expedition_id')
                        ->label('Transportir / Expedition')
                        ->options(Expedition::where('status', 'active')->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->live()
                        ->columnSpan(2),

                    Forms\Components\Select::make('delivery_order_id')
                        ->label('Delivery Order (optional)')
                        ->options(function (Forms\Get $get) {
                            $expeditionId = $get('expedition_id');
                            if (! $expeditionId) {
                                return [];
                            }

                            return \App\Models\DeliveryOrder::where('expedition_id', $expeditionId)
                                ->orderBy('do_number')
                                ->get()
                                ->mapWithKeys(fn($do) => [$do->id => $do->do_number . ' — ' . $do->cylinder_type . ' (' . number_format($do->quantity_ordered) . ' pcs)']);
                        })
                        ->searchable()
                        ->nullable()
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('invoice_number')
                        ->label('Invoice Number')
                        ->nullable()
                        ->maxLength(100),

                    Forms\Components\TextInput::make('description')
                        ->label('Description')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('amount')
                        ->label('Amount (Rp)')
                        ->numeric()
                        ->prefix('Rp')
                        ->minValue(0)
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('paid_amount')
                        ->label('Paid Amount (Rp)')
                        ->numeric()
                        ->prefix('Rp')
                        ->minValue(0)
                        ->default(0)
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('due_date')
                        ->label('Due Date')
                        ->nullable()
                        ->columnSpan(1),

                    Forms\Components\Select::make('status')
                        ->options([
                            'pending' => 'Pending',
                            'paid'    => 'Lunas',
                        ])
                        ->required()
                        ->default('pending')
                        ->columnSpan(1),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        $fmt = fn($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('expedition.name')
                    ->label('Transportir')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('deliveryOrder.do_number')
                    ->label('DO #')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Invoice #')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(40)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing($fmt)
                    ->sortable(),

                Tables\Columns\TextColumn::make('paid_amount')
                    ->label('Paid')
                    ->formatStateUsing($fmt)
                    ->color('success'),

                Tables\Columns\TextColumn::make('balance')
                    ->label('Balance')
                    ->getStateUsing(fn($record) => max(0, (float) $record->amount - (float) $record->paid_amount))
                    ->formatStateUsing($fmt)
                    ->color(fn($state) => $state > 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'pending' => 'warning',
                        'paid'    => 'success',
                        default   => 'gray',
                    })
                    ->formatStateUsing(fn($state) => $state === 'paid' ? 'Lunas' : 'Pending'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'paid'    => 'Lunas',
                    ]),

                Tables\Filters\SelectFilter::make('expedition_id')
                    ->label('Transportir')
                    ->options(Expedition::where('status', 'active')->orderBy('name')->pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\Action::make('mark_as_paid')
                    ->label('Mark as Paid')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(Payable $record) => $record->status !== 'paid')
                    ->action(function (Payable $record) {
                        $record->paid_amount = $record->amount;
                        $record->save();
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Mark as Paid?')
                    ->modalDescription('This will set paid_amount = amount and mark this payable as Lunas.'),

                Tables\Actions\EditAction::make()
                    ->visible(fn(Payable $record) => $record->status !== 'paid'),
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
            'index'  => Pages\ListPayables::route('/'),
            'create' => Pages\CreatePayable::route('/create'),
            'edit'   => Pages\EditPayable::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canViewFinance() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canViewFinance() ?? false;
    }
}
