<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvoiceResource\Pages;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'invoice_number';

    public static function getNavigationLabel(): string
    {
        return 'Invoicing';
    }

    public static function getModelLabel(): string
    {
        return 'Invoice';
    }

    public static function form(Form $form): Form
    {
        $user = auth()->user();
        $isPusat = $user->isOwnerPusat() || $user->isRegionalLeader();

        return $form->schema([
            Forms\Components\Section::make('Invoice Details')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch')
                        ->options(Branch::active()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->disabled(fn() => ! $isPusat)
                        ->dehydrated()
                        ->default(fn() => $isPusat ? null : $user->branch_id)
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('invoice_number')
                        ->label('Invoice Number')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(50)
                        ->default(function () {
                            $year  = date('Y');
                            $count = Invoice::whereYear('created_at', $year)->count() + 1;
                            return 'INV' . $year . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
                        })
                        ->columnSpan(1),

                    Forms\Components\Select::make('customer_id')
                        ->label('Customer')
                        ->options(Customer::active()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->columnSpan(1),

                    Forms\Components\Select::make('cylinder_type')
                        ->label('Cylinder Type')
                        ->options([
                            '3kg'   => '3 kg',
                            '5.5kg' => '5.5 kg',
                            '12kg'  => '12 kg',
                            '50kg'  => '50 kg',
                        ])
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('quantity')
                        ->label('Quantity')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->default(1)
                        ->suffix('pcs')
                        ->live()
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('unit_price')
                        ->label('Unit Price')
                        ->numeric()
                        ->required()
                        ->prefix('Rp')
                        ->minValue(0)
                        ->live()
                        ->columnSpan(1),

                    Forms\Components\Placeholder::make('total_amount_preview')
                        ->label('Total Amount')
                        ->content(fn(Forms\Get $get) => 'Rp ' . number_format(
                            (int) ($get('quantity') ?? 0) * (float) ($get('unit_price') ?? 0),
                            0,
                            ',',
                            '.'
                        ))
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('issue_date')
                        ->label('Issue Date')
                        ->required()
                        ->default(today())
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('due_date')
                        ->label('Due Date')
                        ->required()
                        ->default(today()->addDays(30))
                        ->columnSpan(1),

                    Forms\Components\Select::make('status')
                        ->options([
                            'draft'     => 'Draft',
                            'issued'    => 'Issued',
                            'paid'      => 'Paid',
                            'cancelled' => 'Cancelled',
                        ])
                        ->required()
                        ->default('draft')
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('paid_amount')
                        ->label('Paid Amount')
                        ->numeric()
                        ->prefix('Rp')
                        ->minValue(0)
                        ->default(0)
                        ->columnSpan(1),
                ])->columns(2),

            Forms\Components\Section::make('Notes')
                ->schema([
                    Forms\Components\Textarea::make('notes')
                        ->nullable()
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = auth()->user();
        $isPusat = $user->isOwnerPusat() || $user->isRegionalLeader();

        return $table
            ->modifyQueryUsing(function ($query) use ($user, $isPusat) {
                if (! $isPusat && $user->branch_id) {
                    $query->where('branch_id', $user->branch_id);
                }
            })
            ->columns([
                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Invoice #')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->searchable(),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Customer')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('cylinder_type')
                    ->label('Type')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Qty')
                    ->formatStateUsing(fn($state) => number_format($state) . ' pcs'),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn($state) => 'Rp ' . number_format($state, 0, ',', '.')),

                Tables\Columns\TextColumn::make('paid_amount')
                    ->label('Paid')
                    ->formatStateUsing(fn($state) => 'Rp ' . number_format($state, 0, ',', '.')),

                Tables\Columns\TextColumn::make('balance')
                    ->label('Balance')
                    ->getStateUsing(fn(Invoice $record): string => 'Rp ' . number_format($record->balance, 0, ',', '.'))
                    ->color(fn(Invoice $record): string => $record->balance > 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'draft'     => 'gray',
                        'issued'    => 'info',
                        'partial'   => 'warning',
                        'paid'      => 'success',
                        'overdue'   => 'danger',
                        'cancelled' => 'gray',
                        default     => 'gray',
                    }),

                Tables\Columns\TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date('d M Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft'     => 'Draft',
                        'issued'    => 'Issued',
                        'partial'   => 'Partial',
                        'paid'      => 'Paid',
                        'overdue'   => 'Overdue',
                        'cancelled' => 'Cancelled',
                    ]),

                Tables\Filters\SelectFilter::make('cylinder_type')
                    ->options([
                        '3kg'   => '3 kg',
                        '5.5kg' => '5.5 kg',
                        '12kg'  => '12 kg',
                        '50kg'  => '50 kg',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('pay')
                    ->label('Record Payment')
                    ->icon('heroicon-o-currency-dollar')
                    ->color('success')
                    ->visible(fn(Invoice $record) => in_array($record->status, ['issued', 'partial', 'overdue']))
                    ->form([
                        Forms\Components\TextInput::make('payment_amount')
                            ->label('Payment Amount')
                            ->numeric()
                            ->required()
                            ->prefix('Rp')
                            ->minValue(1),
                    ])
                    ->action(function (Invoice $record, array $data) {
                        $record->paid_amount += (float) $data['payment_amount'];
                        $record->save();
                        $record->recalculateStatus();
                    })
                    ->requiresConfirmation(),

                Tables\Actions\EditAction::make()
                    ->visible(fn(Invoice $record) => $record->status === 'draft'),

                Tables\Actions\Action::make('issue')
                    ->label('Issue')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(Invoice $record) => $record->status === 'draft')
                    ->action(fn(Invoice $record) => $record->update(['status' => 'issued']))
                    ->requiresConfirmation(),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn(Invoice $record) => $record->status === 'draft'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit'   => Pages\EditInvoice::route('/{record}/edit'),
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
