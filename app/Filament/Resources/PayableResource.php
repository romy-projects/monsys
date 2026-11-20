<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PayableResource\Pages;
use App\Models\DeliveryOrder;
use App\Models\Payable;
use App\Models\PayablePayment;
use App\Models\Transportir;
use App\Services\PaymentLedger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

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
                    Forms\Components\Select::make('transportir_id')
                        ->label('Transportir')
                        ->options(Transportir::where('status', 'active')->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->live()
                        ->columnSpan(2),

                    Forms\Components\Select::make('delivery_order_id')
                        ->label('Loading Order (LO)')
                        ->options(function (Forms\Get $get) {
                            $transportirId = $get('transportir_id');
                            if (! $transportirId) {
                                return [];
                            }

                            // BALI-17: only Transportir LOs (not DOs) are billed,
                            // and only the ones belonging to the chosen transportir.
                            return DeliveryOrder::query()
                                ->transportirLoadingOrders()
                                ->where('transportir_id', $transportirId)
                                ->orderBy('do_number')
                                ->get()
                                ->mapWithKeys(fn($lo) => [$lo->id => $lo->do_number . ' - ' . $lo->cylinder_type . ' (' . number_format((float) $lo->quantity_ordered) . ' pcs)']);
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

                    Forms\Components\Placeholder::make('paid_amount_display')
                        ->label('Paid Amount (Rp)')
                        ->content(fn(?Payable $record) => $record
                            ? 'Rp ' . number_format((float) $record->paid_amount, 0, ',', '.') . ' dari Rp ' . number_format((float) $record->amount, 0, ',', '.')
                            : 'Rp 0')
                        ->helperText('Diisi otomatis dari riwayat pembayaran (aksi Catat Pembayaran).')
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('due_date')
                        ->label('Due Date')
                        ->nullable()
                        ->columnSpan(1),

                    Forms\Components\Placeholder::make('status_display')
                        ->label('Status')
                        ->content(fn(?Payable $record) => match ($record?->payment_state) {
                            'paid'    => 'Lunas',
                            'partial' => 'Sebagian',
                            default   => 'Pending',
                        })
                        ->columnSpan(1),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        $fmt = fn($state) => 'Rp ' . number_format((float) $state, 0, ',', '.');

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('transportir.name')
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
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn(Payable $record) => $record->payment_state)
                    ->color(fn(string $state): string => match ($state) {
                        'paid'    => 'success',
                        'partial' => 'warning',
                        default   => 'gray',
                    })
                    ->formatStateUsing(fn(string $state) => match ($state) {
                        'paid'    => 'Lunas',
                        'partial' => 'Sebagian',
                        default   => 'Pending',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'paid'    => 'Lunas',
                    ]),

                Tables\Filters\SelectFilter::make('transportir_id')
                    ->label('Transportir')
                    ->options(Transportir::where('status', 'active')->orderBy('name')->pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\Action::make('record_payment')
                    ->label('Catat Pembayaran')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn(Payable $record) => $record->balance > 0)
                    ->form(fn(Payable $record): array => [
                        Forms\Components\DatePicker::make('paid_on')
                            ->label('Tanggal Bayar')
                            ->default(today())
                            ->required(),
                        Forms\Components\TextInput::make('amount')
                            ->label('Jumlah (Rp)')
                            ->numeric()
                            ->prefix('Rp')
                            ->minValue(0.01)
                            ->maxValue((float) $record->balance)
                            ->helperText('Sisa tagihan: Rp ' . number_format((float) $record->balance, 0, ',', '.'))
                            ->required(),
                        Forms\Components\Select::make('method')
                            ->label('Metode')
                            ->options(PayablePayment::methods())
                            ->default('cash')
                            ->required(),
                        Forms\Components\TextInput::make('reference_no')
                            ->label('No. Referensi')
                            ->maxLength(100)
                            ->nullable(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Catatan')
                            ->rows(2)
                            ->nullable(),
                    ])
                    ->action(function (Payable $record, array $data) {
                        try {
                            app(PaymentLedger::class)->recordPayable($record, $data, auth()->id());
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Pembayaran gagal dicatat')
                                ->body(collect($e->errors())->flatten()->first())
                                ->danger()
                                ->send();
                        }
                    })
                    ->modalHeading('Catat Pembayaran Payable')
                    ->successNotificationTitle('Pembayaran dicatat'),

                Tables\Actions\Action::make('mark_as_paid')
                    ->label('Lunasi')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(Payable $record) => $record->balance > 0)
                    ->action(function (Payable $record) {
                        app(PaymentLedger::class)->recordPayable($record, [
                            'amount' => (float) $record->balance,
                            'method' => 'other',
                            'notes'  => 'Pelunasan penuh (Mark as Paid)',
                        ], auth()->id());
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Tandai lunas?')
                    ->modalDescription('Sisa tagihan dicatat sebagai satu pembayaran penuh.')
                    ->successNotificationTitle('Payable dilunasi'),

                Tables\Actions\Action::make('payments')
                    ->label('Riwayat')
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->visible(fn(Payable $record) => $record->payments()->exists())
                    ->modalHeading(fn(Payable $record) => 'Riwayat Pembayaran - ' . ($record->deliveryOrder?->do_number ?? $record->description))
                    ->modalContent(fn(Payable $record) => view('filament.finance.payment-history', [
                        'payments' => $record->payments()->with('createdBy')->orderByDesc('paid_on')->orderByDesc('id')->get(),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),

                Tables\Actions\Action::make('void_payment')
                    ->label('Batalkan Pembayaran')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn(Payable $record) => (auth()->user()?->isOwnerPusat() ?? false) && $record->payments()->exists())
                    ->form(fn(Payable $record): array => [
                        Forms\Components\Select::make('payment_id')
                            ->label('Pembayaran yang dibatalkan')
                            ->options($record->payments()
                                ->orderByDesc('paid_on')
                                ->orderByDesc('id')
                                ->get()
                                ->mapWithKeys(fn(PayablePayment $payment) => [
                                    $payment->id => $payment->paid_on?->format('d M Y')
                                        . ' - Rp ' . number_format((float) $payment->amount, 0, ',', '.')
                                        . ' (' . $payment->method_label . ')',
                                ]))
                            ->required(),
                    ])
                    ->action(function (Payable $record, array $data) {
                        $payment = $record->payments()->find($data['payment_id']);

                        if ($payment) {
                            app(PaymentLedger::class)->reversePayablePayment($payment);
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan pembayaran?')
                    ->modalDescription('Baris pembayaran dihapus dan total paid_amount dihitung ulang.')
                    ->successNotificationTitle('Pembayaran dibatalkan'),

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

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isOwnerPusat() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canViewFinance() ?? false;
    }
}
