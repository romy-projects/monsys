<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ChartOfAccountResource\Pages;
use App\Models\ChartOfAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Phase 19A / T19A-01: read + maintain the merged chart of accounts.
 */
class ChartOfAccountResource extends Resource
{
    protected static ?string $model = ChartOfAccount::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('nav.item.finance_coa');
    }

    public static function getModelLabel(): string
    {
        return 'Chart of Account';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Chart of Accounts';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Account')->schema([
                Forms\Components\TextInput::make('code')
                    ->label('Kode Akun')
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true),

                Forms\Components\TextInput::make('name')
                    ->label('Nama Akun')
                    ->required()
                    ->maxLength(120),

                Forms\Components\Select::make('pos')
                    ->label('Posisi Laporan')
                    ->options(ChartOfAccount::posLabels())
                    ->default(ChartOfAccount::POS_LABA_RUGI)
                    ->required(),

                Forms\Components\Select::make('account_group')
                    ->label('Kelompok')
                    ->options(ChartOfAccount::groupLabels())
                    ->required(),

                Forms\Components\Select::make('saldo_normal')
                    ->label('Saldo Normal')
                    ->options(['D' => 'Debit', 'K' => 'Kredit'])
                    ->default('D')
                    ->required(),

                Forms\Components\TextInput::make('sort_order')
                    ->label('Urutan')
                    ->numeric()
                    ->default(0),

                Forms\Components\Toggle::make('is_cash_account')
                    ->label('Kas / Bank')
                    ->helperText('Akun ini bisa dipilih sebagai sumber pembayaran.')
                    ->default(false),

                Forms\Components\Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),

                Forms\Components\Textarea::make('description')
                    ->label('Keterangan')
                    ->rows(2)
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Akun')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('pos')
                    ->label('Posisi')
                    ->formatStateUsing(fn (string $state): string => ChartOfAccount::posLabels()[$state] ?? $state),

                Tables\Columns\TextColumn::make('account_group')
                    ->label('Kelompok')
                    ->formatStateUsing(fn (string $state): string => ChartOfAccount::groupLabels()[$state] ?? $state)
                    ->sortable(),

                Tables\Columns\TextColumn::make('saldo_normal')
                    ->label('Saldo')
                    ->formatStateUsing(fn (string $state): string => $state === 'K' ? 'Kredit' : 'Debit'),

                Tables\Columns\IconColumn::make('is_cash_account')
                    ->label('Kas/Bank')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                Tables\Columns\TextColumn::make('operational_costs_count')
                    ->label('Dipakai')
                    ->counts('operationalCosts')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('pos')
                    ->label('Posisi')
                    ->options(ChartOfAccount::posLabels()),

                Tables\Filters\SelectFilter::make('account_group')
                    ->label('Kelompok')
                    ->options(ChartOfAccount::groupLabels()),

                Tables\Filters\TernaryFilter::make('is_active')->label('Aktif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChartOfAccounts::route('/'),
            'create' => Pages\CreateChartOfAccount::route('/create'),
            'edit' => Pages\EditChartOfAccount::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('coa.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasPermission('coa.manage') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->hasPermission('coa.manage') ?? false;
    }

    public static function canDelete($record): bool
    {
        return false; // accounts are referenced by history; deactivate instead
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasPermission('coa.view') ?? false;
    }
}
