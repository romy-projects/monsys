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
        $isPusat = auth()->user()?->isOwnerPusat() || auth()->user()?->isRegionalLeader();

        return $form->schema([
            Forms\Components\Section::make('Close Details')
                ->schema([
                    Forms\Components\Select::make('branch_id')
                        ->label('Branch')
                        ->options(Branch::active()->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->required()
                        ->default(fn () => auth()->user()->branch_id)
                        ->disabled(! $isPusat)
                        ->dehydrated()
                        ->columnSpan(2),

                    Forms\Components\DatePicker::make('close_date')
                        ->label('Close Date')
                        ->required()
                        ->default(today())
                        ->maxDate(today()),

                    Forms\Components\Select::make('cylinder_type')
                        ->options([
                            '3kg'   => '3 kg',
                            '5.5kg' => '5.5 kg',
                            '12kg'  => '12 kg',
                            '50kg'  => '50 kg',
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
                        'draft'     => 'gray',
                        'submitted' => 'warning',
                        'verified'  => 'success',
                        default     => 'gray',
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
                        'draft'     => 'Draft',
                        'submitted' => 'Submitted',
                        'verified'  => 'Verified',
                    ]),

                Tables\Filters\SelectFilter::make('cylinder_type')
                    ->options([
                        '3kg'   => '3 kg',
                        '5.5kg' => '5.5 kg',
                        '12kg'  => '12 kg',
                        '50kg'  => '50 kg',
                    ]),

                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(Branch::active()->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn () => auth()->user()?->isOwnerPusat() || auth()->user()?->isRegionalLeader()),
            ])
            ->actions([
                Tables\Actions\Action::make('submit')
                    ->label('Submit')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->visible(fn (StockClose $record) =>
                        $record->status === 'draft' &&
                        ($record->branch_id === auth()->user()?->branch_id ||
                         auth()->user()?->isOwnerPusat() ||
                         auth()->user()?->isRegionalLeader())
                    )
                    ->action(fn (StockClose $record) => $record->update([
                        'status'       => 'submitted',
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
                    ->visible(fn (StockClose $record) =>
                        $record->status === 'submitted' &&
                        (auth()->user()?->isOwnerPusat() || auth()->user()?->isRegionalLeader())
                    )
                    ->action(fn (StockClose $record) => $record->update([
                        'status'      => 'verified',
                        'verified_by' => auth()->id(),
                        'verified_at' => now(),
                    ]))
                    ->requiresConfirmation()
                    ->modalHeading('Verify Stock Close?'),

                Tables\Actions\EditAction::make()
                    ->visible(fn (StockClose $record) => $record->status === 'draft'),
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
            'index'  => Pages\ListStockCloses::route('/'),
            'create' => Pages\CreateStockClose::route('/create'),
            'edit'   => Pages\EditStockClose::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function canEdit(Model $record): bool
    {
        $user = auth()->user();

        return $record->status === 'draft' &&
               ($user?->isOwnerPusat() || $record->branch_id === $user?->branch_id);
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isOwnerPusat() ?? false;
    }
}
