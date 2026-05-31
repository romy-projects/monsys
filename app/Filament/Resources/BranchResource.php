<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BranchResource\Pages;
use App\Models\Branch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('nav.item.master_branches');
    }

    public static function getModelLabel(): string
    {
        return __('nav.item.master_branches');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Branch Information')
                ->description('Basic information about this distribution branch')
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Branch Code')
                        ->placeholder('e.g. CBG-001')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(20)
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('name')
                        ->label('Branch Name')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('city')
                        ->label('City / Kota')
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('province')
                        ->label('Province / Provinsi')
                        ->columnSpan(1),

                    Forms\Components\Textarea::make('address')
                        ->label('Address / Alamat')
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('phone')
                        ->label('Phone / Telepon')
                        ->tel()
                        ->columnSpan(1),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'active'   => '✅ Active',
                            'inactive' => '⛔ Inactive',
                        ])
                        ->default('active')
                        ->columnSpan(1),

                    Forms\Components\Select::make('regional_id')
                        ->label('Regional Parent')
                        ->relationship('subBranches', 'name')
                        ->searchable()
                        ->nullable()
                        ->columnSpan(2)
                        ->helperText('Leave blank if this IS the regional branch'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Branch Name')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold),

                Tables\Columns\TextColumn::make('city')
                    ->label('City')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('province')
                    ->label('Province')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Staff')
                    ->counts('users')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active'   => 'Active',
                        'inactive' => 'Inactive',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            'index'  => Pages\ListBranches::route('/'),
            'create' => Pages\CreateBranch::route('/create'),
            'edit'   => Pages\EditBranch::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessPanel(app(\Filament\Panel::class))
            && in_array(auth()->user()?->role, ['owner_pusat', 'regional_leader']);
    }
}
