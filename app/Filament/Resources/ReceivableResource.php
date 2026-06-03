<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReceivableResource\Pages;
use App\Models\Branch;
use App\Models\Receivable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ReceivableResource extends Resource
{
    protected static ?string $model = Receivable::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'buyer_name';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.receivables');
    }

    public static function getModelLabel(): string
    {
        return 'Receivable';
    }

    public static function form(Form $form): Form
    {
        $isPusat = auth()->user()?->isOwnerPusat() || auth()->user()?->isRegionalLeader();

        return $form->schema([
            Forms\Components\Section::make('Invoice Details')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch')
                        ->options(Branch::active()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->default(fn() => auth()->user()->branch_id)
                        ->disabled(! $isPusat)
                        ->dehydrated()
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('buyer_name')
                        ->label('Buyer / Customer Name')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('buyer_type')
                        ->label('Buyer Type')
                        ->options([
                            'retail'   => 'Retail',
                            'agen'     => 'Agen',
                            'industri' => 'Industri',
                        ])
                        ->required(),

                    Forms\Components\TextInput::make('invoice_number')
                        ->label('Invoice Number')
                        ->nullable()
                        ->maxLength(100),

                    Forms\Components\TextInput::make('amount')
                        ->label('Invoice Amount (Rp)')
                        ->numeric()
                        ->prefix('Rp')
                        ->minValue(0)
                        ->required(),

                    Forms\Components\DatePicker::make('invoice_date')
                        ->label('Invoice Date')
                        ->required()
                        ->default(today()),

                    Forms\Components\DatePicker::make('due_date')
                        ->label('Due Date')
                        ->required()
                        ->default(today()->addDays(30)),

                    Forms\Components\Textarea::make('notes')
                        ->nullable()
                        ->rows(2)
                        ->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        $fmt = fn($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: ! (auth()->user()?->isOwnerPusat() || auth()->user()?->isRegionalLeader())),

                Tables\Columns\TextColumn::make('buyer_name')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold),

                Tables\Columns\TextColumn::make('buyer_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn($state) => match ($state) {
                        'agen'     => 'primary',
                        'industri' => 'warning',
                        default    => 'gray',
                    })
                    ->formatStateUsing(fn($state) => ucfirst($state)),

                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Invoice #')
                    ->searchable()
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
                    ->sortable()
                    ->color(fn($state, $record) => $state?->lt(today()) && $record->status !== 'paid' ? 'danger' : null),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'outstanding' => 'warning',
                        'partial'     => 'info',
                        'paid'        => 'success',
                        'overdue'     => 'danger',
                        default       => 'gray',
                    })
                    ->formatStateUsing(fn($state) => ucfirst($state)),
            ])
            ->defaultSort('due_date', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'outstanding' => 'Outstanding',
                        'partial'     => 'Partial',
                        'paid'        => 'Paid',
                        'overdue'     => 'Overdue',
                    ]),

                Tables\Filters\SelectFilter::make('buyer_type')
                    ->options([
                        'retail'   => 'Retail',
                        'agen'     => 'Agen',
                        'industri' => 'Industri',
                    ]),

                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(Branch::active()->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn() => auth()->user()?->isOwnerPusat() || auth()->user()?->isRegionalLeader()),
            ])
            ->actions([
                Tables\Actions\Action::make('record_payment')
                    ->label('Record Payment')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn(Receivable $record) => $record->status !== 'paid')
                    ->form([
                        Forms\Components\TextInput::make('payment_amount')
                            ->label('Payment Amount (Rp)')
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(0.01)
                            ->required(),
                        Forms\Components\Textarea::make('payment_note')
                            ->label('Note')
                            ->nullable()
                            ->rows(2),
                    ])
                    ->action(function (Receivable $record, array $data) {
                        $record->paid_amount = min(
                            (float) $record->amount,
                            (float) $record->paid_amount + (float) $data['payment_amount']
                        );

                        if (! empty($data['payment_note'])) {
                            $record->notes = trim(($record->notes ?? '') . "\n[Payment] " . $data['payment_note']);
                        }

                        $record->save();
                        $record->recalculateStatus();
                    })
                    ->modalHeading('Record Payment'),

                Tables\Actions\EditAction::make()
                    ->visible(fn(Receivable $record) => $record->status !== 'paid'),
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
            'index'  => Pages\ListReceivables::route('/'),
            'create' => Pages\CreateReceivable::route('/create'),
            'edit'   => Pages\EditReceivable::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        // Hidden — replaced by Invoicing module
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isOwnerPusat() ?? false;
    }
}
