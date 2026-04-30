<?php

namespace App\Providers\Filament;

use App\Filament\Pages\UsageGuide;
use App\Filament\Widgets\AnalizEkibiMudurlukChart;
use App\Filament\Widgets\AdminStatsOverview;
use App\Filament\Widgets\AnnouncementWidget;
use App\Filament\Widgets\FaaliyetIstatistikGrafik;
use App\Filament\Widgets\MudurlukAylikFaaliyetChart;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
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
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
                UsageGuide::class,
            ])
            ->widgets([
                AnnouncementWidget::class,
                MudurlukAylikFaaliyetChart::class,
                AnalizEkibiMudurlukChart::class,
                AdminStatsOverview::class,
                FaaliyetIstatistikGrafik::class,
            ])
            ->navigationGroups([
                NavigationGroup::make()->label('Raporlama'),
                NavigationGroup::make()->label('Yönetim'),
                NavigationGroup::make()->label('Tanımlamalar'),
                NavigationGroup::make()->label('Kurumsal Hafıza'),
                NavigationGroup::make()->label('İletişim'),
                NavigationGroup::make()->label('Yardım'),
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
