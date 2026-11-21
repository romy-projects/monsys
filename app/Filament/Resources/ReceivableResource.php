<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReceivableResource\Pages;
use App\Models\Branch;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Services\PaymentLedger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

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
        $user = auth()->user();
        $accessibleBranchIds = $user?->accessibleBranchIds() ?? [];
        $branchQuery = Branch::active()->orderBy('name');
        $user?->applyAccessibleBranchScope($branchQuery, 'branches.id');
        $branchOptions = $branchQuery->pluck('name', 'id');

        return $form->schema([
            Forms\Components\Section::make('Invoice Details')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch')
                        ->options($branchOptions)
                        ->searchable()
                        ->required()
                        ->default(fn () => in_array(auth()->user()?->branch_id, $accessibleBranchIds, true)
                            ? auth()->user()->branch_id
                            : (count($accessibleBranchIds) === 1 ? $accessibleBranchIds[0] : null))
                        ->disabled(count($accessibleBranchIds) === 1)
                        ->dehydrated()
                        ->columnSpan(2),

                    Forms\Components\Select::make('buyer_type')
                        ->label('Buyer Type')
                        ->options([
                            'retail' => 'Retail',
                            'agen' => 'Agen',
                            'industri' => 'Industri',
                            'branch' => 'Pangkalan/Cabang Lain',
                        ])
                        ->required()
                        ->live()
                        ->columnSpan(2),

                    Forms\Components\Select::make('debtor_branch_id')
                        ->label('Debtor Branch / Cabang-Pangkalan')
                        ->options($branchOptions)
                        ->searchable()
                        ->required(fn (Get $get) => $get('buyer_type') === 'branch')
                        ->visible(fn (Get $get) => $get('buyer_type') === 'branch')
                        ->live()
                        ->afterStateUpdated(function (Forms\Set $set, $state) {
                            if ($state) {
                                $branchQuery = Branch::query();
                                auth()->user()?->applyAccessibleBranchScope($branchQuery, 'branches.id');
                                $branch = $branchQuery->find($state);
                                if ($branch) {
                                    $set('buyer_name', $branch->name);
                                }
                            }
                        })
                        ->columnSpan(2),

                    Forms\Components\TextInput::make('buyer_name')
                        ->label('Buyer / Customer Name')
                        ->required()
                        ->maxLength(255)
                        ->disabled(fn (Get $get) => $get('buyer_type') === 'branch')
                        ->dehydrated()
                        ->helperText(fn (Get $get) => $get('buyer_type') === 'branch'
                            ? 'Auto-filled from selected branch'
                            : null),

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
        $fmt = fn ($state) => 'Rp '.number_format((float) $state, 0, ',', '.');

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: count(auth()->user()?->accessibleBranchIds() ?? []) <= 1),

                Tables\Columns\TextColumn::make('buyer_name')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::SemiBold),

                Tables\Columns\TextColumn::make('buyer_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'agen' => 'primary',
                        'industri' => 'warning',
                        'branch' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'branch' => 'Cabang Lain',
                        default => ucfirst($state),
                    }),

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
                    ->getStateUsing(fn ($record) => max(0, (float) $record->amount - (float) $record->paid_amount))
                    ->formatStateUsing($fmt)
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date('d M Y')
                    ->sortable()
                    ->color(fn ($state, $record) => $state?->lt(today()) && $record->status !== 'paid' ? 'danger' : null),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'outstanding' => 'warning',
                        'partial' => 'info',
                        'paid' => 'success',
                        'overdue' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
            ])
            ->defaultSort('due_date', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'outstanding' => 'Outstanding',
                        'partial' => 'Partial',
                        'paid' => 'Paid',
                        'overdue' => 'Overdue',
                    ]),

                Tables\Filters\SelectFilter::make('buyer_type')
                    ->options([
                        'retail' => 'Retail',
                        'agen' => 'Agen',
                        'industri' => 'Industri',
                        'branch' => 'Pangkalan/Cabang Lain',
                    ]),

                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(function () {
                        $query = Branch::active()->orderBy('name');
                        auth()->user()?->applyAccessibleBranchScope($query, 'branches.id');

                        return $query->pluck('name', 'id');
                    })
                    ->visible(fn () => count(auth()->user()?->accessibleBranchIds() ?? []) > 1),
            ])
            ->actions([
                Tables\Actions\Action::make('record_payment')
                    ->label('Catat Pembayaran')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Receivable $record) => auth()->user()?->hasPermission('receivables.record_payment') && $record->balance > 0
                    )
                    ->authorize(fn (Receivable $record) => auth()->user()?->hasPermission('receivables.record_payment') &&
                        auth()->user()?->canAccessModelRecord($record, 'update')
                    )
                    ->form(fn (Receivable $record): array => [
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
                            ->helperText('Sisa piutang: Rp '.number_format((float) $record->balance, 0, ',', '.'))
                            ->required(),
                        Forms\Components\Select::make('method')
                            ->label('Metode')
                            ->options(ReceivablePayment::methods())
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
                    ->action(function (Receivable $record, array $data) {
                        try {
                            app(PaymentLedger::class)->recordReceivable($record, $data, auth()->id());
                        } catch (ValidationException $e) {
                            Notification::make()
                                ->title('Pembayaran gagal dicatat')
                                ->body(collect($e->errors())->flatten()->first())
                                ->danger()
                                ->send();
                        }
                    })
                    ->modalHeading('Catat Pembayaran Piutang')
                    ->successNotificationTitle('Pembayaran dicatat'),

                Tables\Actions\Action::make('payments')
                    ->label('Riwayat')
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->visible(fn (Receivable $record) => $record->payments()->exists())
                    ->modalHeading(fn (Receivable $record) => 'Riwayat Pembayaran - '.$record->buyer_name)
                    ->modalContent(fn (Receivable $record) => view('filament.finance.payment-history', [
                        'payments' => $record->payments()->with('createdBy')->orderByDesc('paid_on')->orderByDesc('id')->get(),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),

                Tables\Actions\Action::make('void_payment')
                    ->label('Batalkan Pembayaran')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (Receivable $record) => auth()->user()?->hasPermission('receivables.reverse_payment') && $record->payments()->exists()
                    )
                    ->authorize(fn (Receivable $record) => auth()->user()?->hasPermission('receivables.reverse_payment') &&
                        auth()->user()?->canAccessModelRecord($record, 'update')
                    )
                    ->form(fn (Receivable $record): array => [
                        Forms\Components\Select::make('payment_id')
                            ->label('Pembayaran yang dibatalkan')
                            ->options($record->payments()
                                ->orderByDesc('paid_on')
                                ->orderByDesc('id')
                                ->get()
                                ->mapWithKeys(fn (ReceivablePayment $payment) => [
                                    $payment->id => $payment->paid_on?->format('d M Y')
                                        .' - Rp '.number_format((float) $payment->amount, 0, ',', '.')
                                        .' ('.$payment->method_label.')',
                                ]))
                            ->required(),
                    ])
                    ->action(function (Receivable $record, array $data) {
                        $payment = $record->payments()->find($data['payment_id']);

                        if ($payment) {
                            app(PaymentLedger::class)->reverseReceivablePayment($payment);
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan pembayaran?')
                    ->modalDescription('Baris pembayaran dihapus dan total paid_amount dihitung ulang.')
                    ->successNotificationTitle('Pembayaran dibatalkan'),

                Tables\Actions\EditAction::make()
                    ->visible(fn (Receivable $record) => $record->status !== 'paid'),
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
            'index' => Pages\ListReceivables::route('/'),
            'create' => Pages\CreateReceivable::route('/create'),
            'edit' => Pages\EditReceivable::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('receivables.view') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasPermission('receivables.view') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return static::can('delete', $record);
    }
}
