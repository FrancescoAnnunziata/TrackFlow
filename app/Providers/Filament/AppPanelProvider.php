<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\ChangePassword;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\NotificationPreferences;
use App\Filament\Widgets\AssetStatsOverview;
use App\Http\Middleware\MustChangePassword;
use App\Support\Impersonation;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('/')
            ->brandName('TrackFlow')
            ->brandLogo(new HtmlString('<div style="display:flex;align-items:center;gap:0.5rem;"><img src="'.asset('images/LogoBlack.png').'" alt="TrackFlow" style="height:2rem;"><span style="font-weight:700;font-size:1.25rem;">TrackFlow</span></div>'))
            // Icona della tab del browser: logo dell'app.
            ->favicon(asset('images/LogoBlack.png'))
            ->login(Login::class)
            // Reset password dalla schermata di login ("Password dimenticata?").
            ->passwordReset()
            // Voce nel menu utente per cambiare password dall'app.
            ->userMenuItems([
                Action::make('changePassword')
                    ->label('Cambia password')
                    ->icon('heroicon-o-key')
                    ->url(fn (): string => ChangePassword::getUrl()),
                Action::make('notificationPreferences')
                    ->label('Preferenze notifiche')
                    ->icon('heroicon-o-bell')
                    ->visible(fn (): bool => ! auth()->user()?->isClient())
                    ->url(fn (): string => NotificationPreferences::getUrl()),
            ])
            ->colors([
                'primary' => Color::Amber,
            ])
            // Sidebar riducibile a sole icone su desktop: compare un toggle che la
            // collassa (hover su un'icona per il tooltip del nome). Su mobile resta
            // il comportamento a comparsa standard.
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                AssetStatsOverview::class,
                FilamentInfoWidget::class,
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
                MustChangePassword::class,
            ]);
    }

    public function register(): void
    {
        parent::register();
        FilamentView::registerRenderHook('panels::body.end', fn (): string => Blade::render("@vite('resources/js/app.js')"));

        FilamentView::registerRenderHook(
            'panels::body.start',
            fn (): string => Impersonation::isImpersonating()
                ? view('filament.impersonation-banner', [
                    'impersonator' => Impersonation::impersonator(),
                    'impersonated' => auth()->user(),
                ])->render()
                : '',
        );
    }
}
