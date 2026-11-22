<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OpeningBalanceResource\Pages;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\OpeningBalance;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Phase 19B / T19B-12: enter the balances carried over from the client's
 * previous book (docs/lapkeu-gap-analysis.md §6 batch 2).
 *
 * Every row is stated in the account's normal direction, exactly like the `NS`
 * sheet; `JournalMapping` turns a branch's rows into one balanced `Saldo Awal`
 * entry, so the page only guards what the workbook guarantees.
 */
class OpeningBalanceResource extends Resource
{
    protected static ?string $model = OpeningBalance::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('nav.item.finance_opening_balance');
    }

    public static function getModelLabel(): string
    {
        return 'Saldo Awal';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Saldo Awal';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Saldo Awal')->schema([
                Forms\Components\Select::make('branch_id')
                    ->label('Branch')
                    ->options(function (): array {
                        $query = Branch::active()->orderBy('name');
                        auth()->user()?->applyAccessibleBranchScope($query, 'branches.id');

                        return $query->pluck('name', 'id')->all();
                    })
                    ->searchable()
                    ->required(),

                Forms\Components\Select::make('chart_of_account_id')
                    ->label('Akun COA')
                    ->options(fn (): array => ChartOfAccount::query()
                        ->active()
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (ChartOfAccount $account): array => [
                            $account->id => $account->code.' - '.$account->name,
                        ])
                        ->all())
                    ->searchable()
                    ->required(),

                Forms\Components\DatePicker::make('as_of_date')
                    ->label('Per Tanggal')
                    ->default(now()->startOfYear()->toDateString())
                    ->required(),

                Forms\Components\TextInput::make('amount')
                    ->label('Saldo')
                    ->numeric()
                    ->required()
                    ->helperText('Diisi sesuai saldo normal akun: aset/biaya = debit, kewajiban/ekuitas/pendapatan = kredit.'),

                Forms\Components\Textarea::make('notes')
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
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->sortable(),

                Tables\Columns\TextColumn::make('account.code')
                    ->label('Kode')
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('account.name')
                    ->label('Nama Akun')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('account.saldo_normal')
                    ->label('Saldo Normal')
                    ->formatStateUsing(fn (?string $state): string => $state === 'K' ? 'Kredit' : 'Debit'),

                Tables\Columns\TextColumn::make('as_of_date')
                    ->label('Per Tanggal')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Saldo')
                    ->numeric(decimalPlaces: 0)
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Keterangan')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->relationship('branch', 'name'),

                Tables\Filters\SelectFilter::make('chart_of_account_id')
                    ->label('Akun')
                    ->relationship('account', 'name'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('as_of_date', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOpeningBalances::route('/'),
            'create' => Pages\CreateOpeningBalance::route('/create'),
            'edit' => Pages\EditOpeningBalance::route('/{record}/edit'),
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
        // Deleting a batch voids its `Saldo Awal` entry on the next sync; only
        // finance managers may do it.
        return auth()->user()?->hasPermission('coa.manage') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasPermission('coa.view') ?? false;
    }
}
