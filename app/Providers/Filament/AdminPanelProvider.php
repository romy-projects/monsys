<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
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
            ->login()
            ->darkMode(true)
            ->brandName('SUM Energy Network')
            ->brandLogo(asset('images/logo.svg'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('images/favicon.ico'))
            ->colors([
                'primary'   => Color::hex('#1e3a5f'),   // Deep Navy Blue
                'secondary' => Color::hex('#2d6a9f'),   // Steel Blue
                'info'      => Color::hex('#3b82f6'),   // Bright Blue
                'success'   => Color::hex('#10b981'),   // Emerald
                'warning'   => Color::hex('#f59e0b'),   // Amber
                'danger'    => Color::hex('#ef4444'),   // Red
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
                \App\Filament\Widgets\StatsOverviewWidget::class,
                \App\Filament\Widgets\SalesChartWidget::class,
                \App\Filament\Widgets\StockAlertWidget::class,
                \App\Filament\Widgets\PendingDoWidget::class,
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
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
