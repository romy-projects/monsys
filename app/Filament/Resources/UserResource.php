<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Branch;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('nav.item.master_users');
    }

    public static function getModelLabel(): string
    {
        return 'User';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Account Information')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Full Name / Nama Lengkap')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255)
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->required(fn (string $context) => $context === 'create')
                        ->dehydrated(fn ($state) => filled($state))
                        ->minLength(8)
                        ->helperText('Leave blank on edit to keep current password')
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('phone')
                        ->label('Phone / Telepon')
                        ->tel()
                        ->nullable()
                        ->columnSpan(1),
                ])->columns(2),

            Forms\Components\Section::make('Role & Access')
                ->schema([
                    Forms\Components\Select::make('role')
                        ->label('Role / Level Akses')
                        ->options([
                            'owner_pusat'     => '👑 Level 1 — Owner Pusat',
                            'regional_leader' => '🌍 Level 2 — Regional Leader',
                            'owner_cabang'    => '🏪 Level 3 — Owner Cabang',
                            'staff_gudang'    => '📦 Level 4 — Staff Gudang',
                        ])
                        ->required()
                        ->reactive()
                        ->columnSpan(1),

                    Forms\Components\Select::make('branch_id')
                        ->label('Branch / Cabang')
                        ->options(Branch::active()->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->visible(fn (Forms\Get $get) => $get('role') !== 'owner_pusat')
                        ->required(fn (Forms\Get $get) => in_array($get('role'), ['owner_cabang', 'staff_gudang']))
                        ->columnSpan(1),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'active'   => '✅ Active',
                            'inactive' => '⛔ Inactive',
                        ])
                        ->default('active')
                        ->required()
                        ->columnSpan(1),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('branch'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::SemiBold),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'owner_pusat'     => 'danger',
                        'regional_leader' => 'warning',
                        'owner_cabang'    => 'info',
                        'staff_gudang'    => 'gray',
                        default           => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'owner_pusat'     => '👑 Owner Pusat',
                        'regional_leader' => '🌍 Regional Leader',
                        'owner_cabang'    => '🏪 Owner Cabang',
                        'staff_gudang'    => '📦 Staff Gudang',
                        default           => $state,
                    }),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Branch')
                    ->searchable()
                    ->default('— (All Branches)'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Phone')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'danger'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Joined')
                    ->date('d M Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')
                    ->options([
                        'owner_pusat'     => 'Owner Pusat',
                        'regional_leader' => 'Regional Leader',
                        'owner_cabang'    => 'Owner Cabang',
                        'staff_gudang'    => 'Staff Gudang',
                    ]),

                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(Branch::active()->pluck('name', 'id')),

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
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isOwnerPusat() ?? false;
    }
}
