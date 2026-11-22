<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvoiceResource\Pages;
use App\Filament\Support\OutstandingReconciliationSection;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentLedger;
use App\Support\InvoicePricing;
use App\Support\LpgPriceFormState;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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
        $branchIds = $user?->accessibleBranchIds() ?? [];
        $branchQuery = Branch::active()->orderBy('name');
        $user?->applyAccessibleBranchScope($branchQuery, 'branches.id');
        $branchOptions = $branchQuery->pluck('name', 'id');
        $canChooseBranch = $user?->canAccessAllBranches() || count($branchIds) > 1;
        $defaultBranchId = $user?->branch_id !== null && in_array((int) $user->branch_id, $branchIds, true)
            ? $user->branch_id
            : ($branchIds[0] ?? null);
        $defaultBranchId = $user?->canAccessAllBranches() ? null : $defaultBranchId;

        return $form->schema([
            Forms\Components\Section::make('Invoice Details')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch')
                        ->options($branchOptions)
                        ->searchable()
                        ->required()
                        ->disabled(! $canChooseBranch)
                        ->dehydrated()
                        ->default($defaultBranchId)
                        ->live()
                        ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => LpgPriceFormState::sync($get, $set, 'unit_price'))
                        ->columnSpan(1),

                    // Allocated server-side in handleRecordCreation() — the old
                    // count()+1 default collided under concurrent creates (T11-09).
                    Forms\Components\TextInput::make('invoice_number')
                        ->label('Invoice Number')
                        ->placeholder('Auto: INV2026-001')
                        ->nullable()
                        ->unique(ignoreRecord: true)
                        ->maxLength(50)
                        ->helperText('Leave blank to allocate the next invoice number automatically.')
                        ->columnSpan(1),

                    // Reference: Pusat invoices target branches; Branch invoices target customers
                    Forms\Components\Select::make('reference_type')
                        ->label('Reference Type')
                        ->options([
                            'customer' => 'Customer',
                            'branch' => 'Branch',
                        ])
                        ->default(fn () => $canChooseBranch ? 'branch' : 'customer')
                        ->required()
                        ->live()
                        ->visible(fn () => $canChooseBranch)
                        ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => LpgPriceFormState::sync($get, $set, 'unit_price', false, true))
                        ->columnSpan(1),

                    Forms\Components\Select::make('reference_id')
                        ->label('Customer / Branch')
                        ->options(function (Forms\Get $get) use ($user, $canChooseBranch) {
                            $type = $get('reference_type') ?? ($canChooseBranch ? 'branch' : 'customer');

                            if ($type === 'branch') {
                                $query = Branch::active()->where('id', '!=', $user?->branch_id);
                                $user?->applyAccessibleBranchScope($query, 'branches.id');

                                return $query
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            }

                            $query = Customer::active()->orderBy('name');

                            return $query->pluck('name', 'id');
                        })
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => LpgPriceFormState::sync($get, $set, 'unit_price', false, true))
                        ->columnSpan(1),

                    Forms\Components\Select::make('cylinder_type')
                        ->label('Cylinder Type')
                        ->options([
                            '3kg' => '3 kg',
                            '5.5kg' => '5.5 kg',
                            '12kg' => '12 kg',
                            '50kg' => '50 kg',
                        ])
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => LpgPriceFormState::sync($get, $set, 'unit_price', false, true))
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
                        ->prefix('Rp')
                        ->disabled()
                        ->dehydrated()
                        ->helperText('Loaded from the effective LPG Price record.')
                        ->live()
                        ->columnSpan(1),

                    Forms\Components\Hidden::make('lpg_price_id'),

                    Forms\Components\Placeholder::make('lpg_price_reference')
                        ->label('LPG Price Reference')
                        ->content(fn (Forms\Get $get) => $get('lpg_price_id')
                            ? 'LPG Price #'.$get('lpg_price_id')
                            : 'Select branch and cylinder type to load the effective LPG Price.')
                        ->columnSpan(1),

                    Forms\Components\Placeholder::make('total_amount_preview')
                        ->label('Total Amount (incl. delivery fee)')
                        ->content(fn (Forms\Get $get) => 'Rp '.number_format(InvoicePricing::total([
                            'unit_price' => (float) ($get('unit_price') ?? 0),
                            'quantity' => (float) ($get('quantity') ?? 0),
                            'expedition_fee_amount' => (float) ($get('expedition_fee_amount') ?? 0),
                        ]), 0, ',', '.'))
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('issue_date')
                        ->label('Issue Date')
                        ->required()
                        ->default(today())
                        ->live()
                        ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => LpgPriceFormState::sync($get, $set, 'unit_price', false, true))
                        ->columnSpan(1),

                    Forms\Components\DatePicker::make('due_date')
                        ->label('Due Date')
                        ->required()
                        ->default(today()->addDays(30))
                        ->columnSpan(1),

                    Forms\Components\Select::make('status')
                        ->options([
                            'draft' => 'Draft',
                            'issued' => 'Issued',
                            'paid' => 'Paid',
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

            // Phase 19B / T19B-11 (D5): the transportir rate and the expedition
            // fee are part of the bill, so they must be visible and editable.
            // Until now only the mobile API could write these columns.
            Forms\Components\Section::make('Transport & Expedition')
                ->description('Delivery cost of this invoice. Edit only when the linked LO data is missing or wrong.')
                ->schema([
                    Forms\Components\Select::make('loading_order_number')
                        ->label('Loading Order')
                        ->placeholder('No LO linked')
                        ->options(function (Forms\Get $get) use ($user) {
                            $query = DeliveryOrder::query()->loadingOrders();

                            if ($user && ! $user->canAccessAllBranches()) {
                                $query->whereIn('destination_branch_id', $user->accessibleBranchIds() ?: [0]);
                            }

                            return $query
                                ->when($get('branch_id'), fn ($q, $branchId) => $q->where('destination_branch_id', (int) $branchId))
                                ->orderByDesc('order_date')
                                ->limit(100)
                                ->pluck('do_number', 'do_number');
                        })
                        ->searchable()
                        ->helperText('Links the invoice to the shipment whose transportir rate and expedition cost are snapshotted.')
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('transportir_rate_at_sale')
                        ->label('Transportir Rate')
                        ->prefix('Rp')
                        ->numeric()
                        ->minValue(0)
                        ->live()
                        ->helperText('Rate per cylinder at the sale date (DPP HPP stays Pertamina only).')
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('expedition_cost_at_sale')
                        ->label('Expedition Cost')
                        ->prefix('Rp')
                        ->numeric()
                        ->minValue(0)
                        ->live()
                        ->helperText('Actual delivery fee paid to the transportir for this shipment.')
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('expedition_fee_amount')
                        ->label('Charged to Buyer')
                        ->prefix('Rp')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->live()
                        ->helperText('Added to the invoice total; may not exceed the expedition cost.')
                        ->columnSpan(1),
                ])->columns(2),

            // D7: open reconciliation credits/claims of the invoiced branch (display only).
            OutstandingReconciliationSection::make('branch_id'),

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
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('lpgPrice'))
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

                Tables\Columns\TextColumn::make('reference_name')
                    ->label('Customer / Branch')
                    ->placeholder('—')
                    ->searchable()
                    ->getStateUsing(fn (Invoice $record) => $record->reference_name ?? '—'),

                Tables\Columns\TextColumn::make('cylinder_type')
                    ->label('Type')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('lpgPrice.id')
                    ->label('LPG Price #')
                    ->placeholder('Legacy')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('lpgPrice.effective_date')
                    ->label('Price Effective')
                    ->date('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('quantity')
                    ->label('Qty')
                    ->formatStateUsing(fn ($state) => number_format($state).' pcs'),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn ($state) => 'Rp '.number_format($state, 0, ',', '.')),

                Tables\Columns\TextColumn::make('loading_order_number')
                    ->label('LO')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('expedition_fee_amount')
                    ->label('Fee to Buyer')
                    ->formatStateUsing(fn ($state) => 'Rp '.number_format((float) $state, 0, ',', '.'))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('paid_amount')
                    ->label('Paid')
                    ->formatStateUsing(fn ($state) => 'Rp '.number_format($state, 0, ',', '.')),

                Tables\Columns\TextColumn::make('balance')
                    ->label('Balance')
                    ->getStateUsing(fn (Invoice $record): string => 'Rp '.number_format($record->balance, 0, ',', '.'))
                    ->color(fn (Invoice $record): string => $record->balance > 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'issued' => 'info',
                        'partial' => 'warning',
                        'paid' => 'success',
                        'overdue' => 'danger',
                        'cancelled' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date('d M Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'issued' => 'Issued',
                        'partial' => 'Partial',
                        'paid' => 'Paid',
                        'overdue' => 'Overdue',
                        'cancelled' => 'Cancelled',
                    ]),

                Tables\Filters\SelectFilter::make('cylinder_type')
                    ->options([
                        '3kg' => '3 kg',
                        '5.5kg' => '5.5 kg',
                        '12kg' => '12 kg',
                        '50kg' => '50 kg',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('pay')
                    ->label('Record Payment')
                    ->icon('heroicon-o-currency-dollar')
                    ->color('success')
                    ->visible(fn (Invoice $record) => auth()->user()?->hasPermission('invoices.pay') &&
                        in_array($record->status, ['issued', 'partial', 'overdue'])
                    )
                    ->authorize(fn (Invoice $record) => auth()->user()?->hasPermission('invoices.pay') &&
                        auth()->user()?->canAccessModelRecord($record, 'update')
                    )
                    ->form([
                        Forms\Components\TextInput::make('payment_amount')
                            ->label('Payment Amount')
                            ->numeric()
                            ->required()
                            ->prefix('Rp')
                            ->minValue(1),

                        Forms\Components\DatePicker::make('paid_on')
                            ->label('Tanggal Bayar')
                            ->default(today())
                            ->required(),

                        Forms\Components\Select::make('method')
                            ->label('Metode Pembayaran')
                            ->options(Payment::methods())
                            ->default('cash')
                            ->required(),

                        Forms\Components\Select::make('cash_account_id')
                            ->label('Masuk ke Kas/Bank')
                            ->options(fn () => ChartOfAccount::cashAccounts()
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (ChartOfAccount $account) => [
                                    $account->id => $account->code.' — '.$account->name,
                                ]))
                            ->searchable(),

                        Forms\Components\TextInput::make('reference_no')
                            ->label('No. Referensi')
                            ->maxLength(50),
                    ])
                    ->action(function (Invoice $record, array $data) {
                        // Phase 19A / T19A-03: record a ledger row instead of
                        // adding to paid_amount, so the payment date and method
                        // survive for the Jurnal / Sales Detail reports.
                        app(PaymentLedger::class)->recordInvoice($record, [
                            'amount' => $data['payment_amount'],
                            'paid_on' => $data['paid_on'] ?? null,
                            'method' => $data['method'] ?? 'cash',
                            'cash_account_id' => $data['cash_account_id'] ?? null,
                            'reference_no' => $data['reference_no'] ?? null,
                        ], auth()->id());

                        Notification::make()
                            ->title('Payment recorded')
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation(),

                Tables\Actions\EditAction::make()
                    ->visible(fn (Invoice $record) => $record->status === 'draft' && static::can('update', $record))
                    ->authorize(fn (Invoice $record) => $record->status === 'draft' && static::can('update', $record)),

                Tables\Actions\Action::make('issue')
                    ->label('Issue')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Invoice $record) => $record->status === 'draft' && auth()->user()?->hasPermission('invoices.issue')
                    )
                    ->authorize(fn (Invoice $record) => $record->status === 'draft' &&
                        auth()->user()?->hasPermission('invoices.issue') &&
                        auth()->user()?->canAccessModelRecord($record, 'update')
                    )
                    ->action(fn (Invoice $record) => $record->update(['status' => 'issued']))
                    ->requiresConfirmation(),

                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Invoice $record) => $record->status === 'draft' && static::can('delete', $record))
                    ->authorize(fn (Invoice $record) => $record->status === 'draft' && static::can('delete', $record)),
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
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('invoices.view') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasPermission('invoices.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasPermission('invoices.create') ?? false;
    }
}
