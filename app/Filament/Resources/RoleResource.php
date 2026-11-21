<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Filament\Resources\RoleResource\RelationManagers\UsersRelationManager;
use App\Models\Permission;
use App\Models\Role;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Phase 17 / T17-04 — access role administration.
 *
 * Gated on the `access_control.manage` permission instead of a `roles.*`
 * catalog key, because role administration is intentionally super-admin only
 * and the permission catalog has no roles module.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = null;

    protected static ?int $navigationSort = 90;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.access');
    }

    public static function getNavigationLabel(): string
    {
        return __('nav.item.access_roles');
    }

    public static function getModelLabel(): string
    {
        return 'Access Role';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Access Roles';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Role')
                ->schema([
                    Forms\Components\TextInput::make('label')
                        ->label('Display name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (string $operation, ?string $state, Forms\Set $set): void {
                            if ($operation !== 'create') {
                                return;
                            }

                            $set('name', Str::slug((string) $state, '_'));
                        }),

                    Forms\Components\TextInput::make('name')
                        ->label('System name')
                        ->required()
                        ->maxLength(255)
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (?Role $record): bool => (bool) $record?->is_system)
                        ->helperText('Used by code and API clients. Locked for built-in roles.'),

                    Forms\Components\Textarea::make('description')
                        ->rows(2)
                        ->maxLength(1000)
                        ->columnSpanFull(),

                    Forms\Components\Select::make('data_scope')
                        ->label('Data scope')
                        ->options(Role::dataScopeOptions())
                        ->required()
                        ->disabled(fn (?Role $record): bool => (bool) $record?->is_super_admin)
                        ->helperText('All branches = head office, assigned branches = regional, own branch = branch staff.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->disabled(fn (?Role $record): bool => (bool) $record?->is_super_admin)
                        ->helperText('Inactive roles grant no permissions and no branch access.'),
                ])->columns(2),

            Forms\Components\Section::make('Permissions')
                ->description('Built-in roles must keep their capability permission (system.* keys).')
                ->schema([
                    Forms\Components\CheckboxList::make('permissions')
                        ->relationship(
                            name: 'permissions',
                            titleAttribute: 'label',
                            modifyQueryUsing: fn (Builder $query): Builder => $query
                                ->orderBy('sort_order')
                                ->orderBy('label'),
                        )
                        ->getOptionLabelFromRecordUsing(
                            fn (Permission $record): string => filled($record->category)
                                ? "{$record->category} — {$record->label}"
                                : (string) $record->label
                        )
                        ->searchable()
                        ->bulkToggleable()
                        ->columns(2)
                        ->disabled(fn (?Role $record): bool => (bool) $record?->is_super_admin)
                        ->rules([
                            fn (Forms\Components\CheckboxList $component): Closure => function (string $attribute, $value, Closure $fail) use ($component): void {
                                $record = $component->getRecord();
                                $requiredKey = $record?->requiredCapabilityKey();

                                if ($requiredKey === null) {
                                    return;
                                }

                                $requiredId = Permission::query()->where('key', $requiredKey)->value('id');

                                if ($requiredId !== null && ! in_array((int) $requiredId, array_map('intval', (array) $value), true)) {
                                    $fail("The {$record->label} role must keep its \"{$requiredKey}\" capability permission.");
                                }
                            },
                        ])
                        ->helperText(fn (?Role $record): string => $record?->is_super_admin
                            ? 'Super Admin implicitly holds every permission in the catalog; this list cannot be edited.'
                            : 'Users receive exactly the permissions selected here, limited by the data scope above.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['permissions', 'users']))
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->label('Role')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::SemiBold),

                Tables\Columns\TextColumn::make('name')
                    ->label('System name')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('scope_label')
                    ->label('Data scope')
                    ->badge()
                    ->color(fn (Role $record): string => match ($record->data_scope) {
                        Role::SCOPE_ALL_BRANCHES => 'danger',
                        Role::SCOPE_ASSIGNED_BRANCHES => 'warning',
                        default => 'info',
                    }),

                Tables\Columns\TextColumn::make('permissions_count')
                    ->label('Permissions')
                    ->numeric()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('users_count')
                    ->label('Users')
                    ->numeric()
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_super_admin')
                    ->label('Protected')
                    ->boolean()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('data_scope')
                    ->label('Data scope')
                    ->options(Role::dataScopeOptions()),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Role $record): bool => $record->canBeDeleted()),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            UsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }

    public static function canManageAccessControl(): bool
    {
        return (bool) auth()->user()?->hasPermission('access_control.manage');
    }

    public static function canAccess(): bool
    {
        return static::canManageAccessControl();
    }

    public static function canViewAny(): bool
    {
        return static::canManageAccessControl();
    }

    public static function canCreate(): bool
    {
        return static::canManageAccessControl();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManageAccessControl() && ! $record->is_super_admin;
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManageAccessControl() && $record->canBeDeleted();
    }
}
