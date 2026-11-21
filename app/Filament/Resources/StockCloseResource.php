<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockCloseResource\Pages;
use App\Models\Branch;
use App\Models\StockClose;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StockCloseResource extends Resource
{
    protected static ?string $model = StockClose::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Stock Management';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'close_date';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.stock_close');
    }

    public static function getModelLabel(): string
    {
        return 'Stock Close';
    }

    public static function form(Form $form): Form
    {
        $user = auth()->user();
        $branchIds = $user?->accessibleBranchIds() ?? [];
        $branchQuery = Branch::active()->orderBy('name');
        $user?->applyAccessibleBranchScope($branchQuery, 'branches.id');
        $branchOptions = $branchQuery->pluck('name', 'id');
        $defaultBranchId = $user?->branch_id !== null && in_array((int) $user->branch_id, $branchIds, true)
            ? $user->branch_id
            : ($branchIds[0] ?? null);
        $singleBranch = count($branchIds) === 1;

        return $form->schema([
            Forms\Components\Section::make('Close Details')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch')
                        ->options($branchOptions)
                        ->searchable()
                        ->required()
                        ->default($defaultBranchId)
                        ->disabled($singleBranch)
                        ->dehydrated()
                        ->columnSpan(2),

                    Forms\Components\DatePicker::make('close_date')
                        ->label('Close Date')
                        ->required()
                        ->default(today())
                        ->maxDate(today()),

                    Forms\Components\Select::make('cylinder_type')
                        ->options([
                            '3kg' => '3 kg',
                            '5.5kg' => '5.5 kg',
                            '12kg' => '12 kg',
                            '50kg' => '50 kg',
                        ])
                        ->required(),

                    Forms\Components\TextInput::make('qty_full')
                        ->label('Qty Full (pcs)')
                        ->numeric()
                        ->minValue(0)
                        ->required(),

                    Forms\Components\TextInput::make('qty_empty')
                        ->label('Qty Empty (pcs)')
                        ->numeric()
                        ->minValue(0)
                        ->required(),

                    Forms\Components\TextInput::make('qty_damaged')
                        ->label('Qty Damaged (pcs)')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),

                    Forms\Components\Textarea::make('notes')
                        ->nullable()
                        ->rows(2)
                        ->columnSpanFull(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('close_date')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('cylinder_type')
                    ->label('Type')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('qty_full')
                    ->label('Full')
                    ->numeric()
                    ->alignRight(),

                Tables\Columns\TextColumn::make('qty_empty')
                    ->label('Empty')
                    ->numeric()
                    ->alignRight(),

                Tables\Columns\TextColumn::make('qty_damaged')
                    ->label('Damaged')
                    ->numeric()
                    ->alignRight()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'submitted' => 'warning',
                        'verified' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => ucfirst($state)),

                Tables\Columns\TextColumn::make('submittedBy.name')
                    ->label('Submitted By')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Submitted At')
                    ->dateTime('d M Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('close_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'submitted' => 'Submitted',
                        'verified' => 'Verified',
                    ]),

                Tables\Filters\SelectFilter::make('cylinder_type')
                    ->options([
                        '3kg' => '3 kg',
                        '5.5kg' => '5.5 kg',
                        '12kg' => '12 kg',
                        '50kg' => '50 kg',
                    ]),

                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(function () {
                        $query = Branch::active()->orderBy('name');
                        auth()->user()?->applyAccessibleBranchScope($query, 'branches.id');

                        return $query->pluck('name', 'id');
                    })
                    ->visible(fn () => auth()->user()?->canAccessAllBranches() || count(auth()->user()?->accessibleBranchIds() ?? []) > 1),
            ])
            ->actions([
                Tables\Actions\Action::make('submit')
                    ->label('Submit')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(fn (StockClose $record) => $record->status === 'draft' &&
                        auth()->user()?->hasPermission('stock_closes.create') &&
                        auth()->user()?->canAccessBranch((int) $record->branch_id)
                    )
                    ->authorize(fn (StockClose $record) => $record->status === 'draft' &&
                        auth()->user()?->hasPermission('stock_closes.create') &&
                        auth()->user()?->canAccessBranch((int) $record->branch_id)
                    )
                    ->action(fn (StockClose $record) => $record->update([
                        'status' => 'submitted',
                        'submitted_by' => auth()->id(),
                        'submitted_at' => now(),
                    ]))
                    ->requiresConfirmation()
                    ->modalHeading('Submit Stock Close?')
                    ->modalDescription('Once submitted, the close record will be locked for verification.'),

                Tables\Actions\Action::make('verify')
                    ->label('Verify')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (StockClose $record) => $record->status === 'submitted' &&
                        auth()->user()?->hasPermission('stock_closes.verify') &&
                        auth()->user()?->canAccessBranch((int) $record->branch_id)
                    )
                    ->authorize(fn (StockClose $record) => $record->status === 'submitted' &&
                        auth()->user()?->hasPermission('stock_closes.verify') &&
                        auth()->user()?->canAccessBranch((int) $record->branch_id)
                    )
                    ->action(fn (StockClose $record) => $record->update([
                        'status' => 'verified',
                        'verified_by' => auth()->id(),
                        'verified_at' => now(),
                    ]))
                    ->requiresConfirmation()
                    ->modalHeading('Verify Stock Close?'),

                Tables\Actions\EditAction::make()
                    ->visible(fn (StockClose $record) => static::canEdit($record))
                    ->authorize(fn (StockClose $record) => static::canEdit($record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn ($query) => $query);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockCloses::route('/'),
            'create' => Pages\CreateStockClose::route('/create'),
            'edit' => Pages\EditStockClose::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return static::can('viewAny');
    }

    public static function canEdit(Model $record): bool
    {
        return $record->status === 'draft' && static::can('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::can('delete', $record);
    }
}
