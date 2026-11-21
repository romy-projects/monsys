<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ImpersonationService;
use App\Services\UserPasswordService;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = null;

    protected static ?string $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 3;

    /** Legacy `users.role` enum values that are mirrored by a built-in access role. */
    public const LEGACY_ROLES = ['owner_pusat', 'regional_leader', 'owner_cabang', 'staff_gudang'];

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user !== null) {
            $user->applyAccessibleBranchScope($query);
        }

        // The protected Super Admin account is a hidden system account: it is
        // not listed here (and, because record resolution uses this query, it
        // cannot be opened for editing either).
        return $query->excludingSuperAdmins();
    }

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
                    Forms\Components\Select::make('role_id')
                        ->label('Access Role')
                        ->options(fn (): array => Role::query()->assignable()->ordered()->pluck('label', 'id')->all())
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set): void {
                            $legacyRole = static::builtInRoleName($state);

                            if ($legacyRole !== null) {
                                $set('role', $legacyRole);
                            }
                        })
                        ->helperText('Permissions and data scope come from this role. Super Admin can only be granted via the console command.')
                        ->exists('roles', 'id')
                        ->rule(fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                            $isSuperAdminRole = Role::query()
                                ->whereKey((int) $value)
                                ->where('is_super_admin', true)
                                ->exists();

                            if ($isSuperAdminRole) {
                                $fail('The Super Admin role can only be granted with the access:bootstrap-super-admin command.');
                            }
                        })
                        ->columnSpan(1),

                    Forms\Components\Select::make('role')
                        ->label('Legacy Role / Level Akses')
                        ->options([
                            'owner_pusat' => '👑 Level 1 — Owner Pusat',
                            'regional_leader' => '🌍 Level 2 — Regional Leader',
                            'owner_cabang' => '🏪 Level 3 — Owner Cabang',
                            'staff_gudang' => '📦 Level 4 — Staff Gudang',
                        ])
                        ->required()
                        ->reactive()
                        ->rule(fn (Forms\Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                            $roleName = static::builtInRoleName($get('role_id'));

                            if ($roleName !== null && $value !== $roleName) {
                                $fail("The legacy role must be \"{$roleName}\" so API and mobile clients stay in sync with the access role.");
                            }
                        })
                        ->helperText('Kept for API/mobile compatibility. Built-in access roles force the matching value.')
                        ->columnSpan(1),

                    Forms\Components\Select::make('branch_id')
                        ->label('Branch / Cabang')
                        ->options(Branch::active()->pluck('name', 'id'))
                        ->searchable()
                        ->nullable()
                        ->visible(fn (Forms\Get $get): bool => $get('role') !== 'owner_pusat'
                            || static::accessRoleScope($get('role_id')) === Role::SCOPE_OWN_BRANCH)
                        ->required(fn (Forms\Get $get): bool => in_array($get('role'), ['owner_cabang', 'staff_gudang'], true)
                            || static::accessRoleScope($get('role_id')) === Role::SCOPE_OWN_BRANCH)
                        ->columnSpan(1),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'active' => '✅ Active',
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
            ->modifyQueryUsing(fn ($query) => $query->with(['branch', 'accessRole']))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::SemiBold),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'owner_pusat' => 'danger',
                        'regional_leader' => 'warning',
                        'owner_cabang' => 'info',
                        'staff_gudang' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'owner_pusat' => '👑 Owner Pusat',
                        'regional_leader' => '🌍 Regional Leader',
                        'owner_cabang' => '🏪 Owner Cabang',
                        'staff_gudang' => '📦 Staff Gudang',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('accessRole.label')
                    ->label('Access Role')
                    ->badge()
                    ->color(fn (User $record): string => $record->accessRole?->is_super_admin ? 'danger' : 'primary')
                    ->description(fn (User $record): ?string => $record->accessRole?->scope_label)
                    ->placeholder('—'),

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
                Tables\Filters\SelectFilter::make('role_id')
                    ->label('Access Role')
                    ->options(fn (): array => Role::query()->ordered()->pluck('label', 'id')->all())
                    ->searchable(),

                Tables\Filters\SelectFilter::make('role')
                    ->options([
                        'owner_pusat' => 'Owner Pusat',
                        'regional_leader' => 'Regional Leader',
                        'owner_cabang' => 'Owner Cabang',
                        'staff_gudang' => 'Staff Gudang',
                    ]),

                Tables\Filters\SelectFilter::make('branch_id')
                    ->label('Branch')
                    ->options(Branch::active()->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('reset_password')
                    ->label('Reset Password')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->modalHeading('Reset password')
                    ->modalDescription(fn (User $record): string => "Set a new password for {$record->email}. Every session and API token issued before the reset stops working.")
                    ->modalSubmitActionLabel('Reset password')
                    ->form([
                        Forms\Components\TextInput::make('password')
                            ->label('New password')
                            ->password()
                            ->revealable()
                            ->default(fn (): string => app(UserPasswordService::class)->defaultPassword())
                            ->helperText(fn (): string => 'Pre-filled with the deployment default (DEFAULT_USER_PASSWORD); it is never written to the audit trail.')
                            ->required()
                            ->minLength(fn (): int => app(UserPasswordService::class)->minimumLength()),
                        Forms\Components\Toggle::make('must_change_password')
                            ->label('Require a password change at next sign-in')
                            ->default(true),
                    ])
                    ->visible(fn (User $record): bool => static::canResetPassword($record))
                    ->action(function (User $record, array $data): void {
                        app(UserPasswordService::class)->reset(
                            $record,
                            $data['password'] ?? null,
                            (bool) ($data['must_change_password'] ?? true),
                        );

                        Notification::make()
                            ->title("Password reset for {$record->email}")
                            ->body(($data['must_change_password'] ?? true)
                                ? 'The user must choose a new password at next sign-in.'
                                : 'The previous password, sessions and API tokens are no longer valid.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('reset_two_factor')
                    ->label('Reset 2FA')
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Reset two-factor authentication')
                    ->modalDescription('The user will log in with their password only and can enrol again from the Security page.')
                    ->visible(fn (User $record): bool => $record->hasTwoFactorEnabled() && static::can('update', $record))
                    ->action(function (User $record): void {
                        $record->disableTwoFactor();

                        app(AuditLogger::class)->record(
                            'user.two_factor_reset',
                            "Two-factor authentication reset for {$record->email}",
                            $record,
                        );

                        Notification::make()
                            ->title('Two-factor authentication reset')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('login_as')
                    ->label('Login As')
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Impersonate this user')
                    ->modalDescription('You will act as this user until you stop impersonating. The session is audited.')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Reason')
                            ->rows(2)
                            ->maxLength(255)
                            ->required((bool) config('impersonation.require_reason')),
                    ])
                    ->visible(function (User $record): bool {
                        $actor = auth()->user();

                        return $actor instanceof User
                            && ! app(ImpersonationService::class)->isActive()
                            && $record->canBeImpersonatedBy($actor);
                    })
                    ->action(function (User $record, array $data, Component $livewire): void {
                        app(ImpersonationService::class)->start($record, $data['reason'] ?? null);

                        $livewire->redirect(Filament::getPanel('admin')->getUrl());
                    }),

                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('reset_password')
                        ->label('Reset Password')
                        ->icon('heroicon-o-key')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Reset passwords')
                        ->modalDescription('Every selected user receives the deployment default password and must choose a new one at next sign-in.')
                        ->action(function (Collection $records): void {
                            $service = app(UserPasswordService::class);
                            $default = $service->defaultPassword();
                            $reset = 0;

                            foreach ($records as $record) {
                                abort_unless(static::canResetPassword($record), 403);

                                $service->reset($record, $default, true);
                                $reset++;
                            }

                            Notification::make()
                                ->title("{$reset} password(s) reset")
                                ->body('The selected users must choose a new password at next sign-in.')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * The legacy role name for a built-in access role, or null for custom roles.
     */
    public static function builtInRoleName(mixed $roleId): ?string
    {
        if (! is_numeric($roleId)) {
            return null;
        }

        $name = Role::query()->whereKey((int) $roleId)->value('name');

        return $name !== null && in_array($name, self::LEGACY_ROLES, true) ? $name : null;
    }

    public static function accessRoleScope(mixed $roleId): ?string
    {
        if (! is_numeric($roleId)) {
            return null;
        }

        return Role::query()->whereKey((int) $roleId)->value('data_scope');
    }

    public static function canAccess(): bool
    {
        return static::can('viewAny');
    }

    public static function canEdit(Model $record): bool
    {
        return ! $record->accessRole?->is_super_admin && static::can('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return ! $record->accessRole?->is_super_admin && static::can('delete', $record);
    }

    /**
     * Only a user who may edit the record may rotate its password, and the
     * protected Super Admin account is never reset from the panel: that would
     * be a privilege-escalation path (take over the account that configures
     * access control). It can still change its own password.
     */
    public static function canResetPassword(Model $record): bool
    {
        return static::canEdit($record);
    }
}
