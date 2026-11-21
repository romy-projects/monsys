<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\ChangePassword;
use App\Filament\Pages\SecuritySettings;
use App\Filament\Widgets\PendingDoWidget;
use App\Filament\Widgets\SalesChartWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Filament\Widgets\StockAlertWidget;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\LogImpersonatedActions;
use App\Http\Middleware\RequireTwoFactorEnrollment;
use App\Services\ImpersonationService;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->darkMode(true)
            ->brandName('SUM Energy Network')
            ->brandLogo(asset('images/logo.svg'))
            ->brandLogoHeight('4.5rem')
            ->favicon(asset('images/favicon.ico'))
            ->colors([
                'primary' => Color::hex('#1e3a5f'),   // Deep Navy Blue
                'secondary' => Color::hex('#2d6a9f'),   // Steel Blue
                'info' => Color::hex('#3b82f6'),   // Bright Blue
                'success' => Color::hex('#10b981'),   // Emerald
                'warning' => Color::hex('#f59e0b'),   // Amber
                'danger' => Color::hex('#ef4444'),   // Red
            ])
            ->font('Inter', 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap')
            ->navigationGroups([
                NavigationGroup::make()
                    ->label(fn () => __('nav.group.dashboard'))
                    ->icon('heroicon-o-chart-bar'),
                NavigationGroup::make()
                    ->label(fn () => __('nav.group.stock'))
                    ->icon('heroicon-o-cube'),
                NavigationGroup::make()
                    ->label(fn () => __('nav.group.delivery'))
                    ->icon('heroicon-o-truck'),
                NavigationGroup::make()
                    ->label(fn () => __('nav.group.sales'))
                    ->icon('heroicon-o-currency-dollar'),
                NavigationGroup::make()
                    ->label(fn () => __('nav.group.finance'))
                    ->icon('heroicon-o-chart-pie'),
                NavigationGroup::make()
                    ->label(fn () => __('nav.group.master'))
                    ->icon('heroicon-o-cog-6-tooth'),
                NavigationGroup::make()
                    ->label(fn () => __('nav.group.reports'))
                    ->icon('heroicon-o-document-text'),
                NavigationGroup::make()
                    ->label(fn () => __('nav.group.access'))
                    ->icon('heroicon-o-shield-check'),
                NavigationGroup::make()
                    ->label('Tabung Sirkulasi')
                    ->icon('heroicon-o-arrow-path'),
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                StatsOverviewWidget::class,
                SalesChartWidget::class,
                StockAlertWidget::class,
                PendingDoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                LogImpersonatedActions::class,
                ForcePasswordChange::class,
                RequireTwoFactorEnrollment::class,
            ])
            ->userMenuItems([
                'change-password' => MenuItem::make()
                    ->label('Change Password')
                    ->icon('heroicon-o-key')
                    ->url(fn (): string => ChangePassword::getUrl(panel: 'admin'))
                    ->sort(5),
                'security' => MenuItem::make()
                    ->label('Security')
                    ->icon('heroicon-o-lock-closed')
                    ->url(fn (): string => SecuritySettings::getUrl(panel: 'admin'))
                    ->sort(10),
            ])
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                function (): string {
                    $impersonation = app(ImpersonationService::class);

                    if (! $impersonation->isActive()) {
                        return '';
                    }

                    return view('filament.components.impersonation-banner', [
                        'target' => auth()->user(),
                        'initiator' => $impersonation->initiator(),
                        'reason' => $impersonation->reason(),
                    ])->render();
                },
            )
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
