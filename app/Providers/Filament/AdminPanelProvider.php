<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\AturBahasa;
use App\Http\Middleware\HeaderKeamanan;
use App\Http\Middleware\JagaHakCipta;
use App\Http\Middleware\SetTenancy;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use App\Support\HakCipta;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
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
            ->login(Login::class) // batas login gagal per IP sama dengan login kasir
            ->brandName('Billing Rental PS')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2.25rem')
            ->favicon('/favicon.ico')
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->darkMode(true)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                // Status sistem, rekap hari ini & ringkasan operasional ditemukan otomatis dari app/Filament/Widgets.
                // Logout ada di menu profil (kanan atas).
            ])
            ->navigationItems([
                NavigationItem::make('Aplikasi Kasir')
                    ->url('/')
                    ->icon('heroicon-o-computer-desktop')
                    ->sort(-10)
                    ->visible(fn () => (bool) auth()->user()?->tenant_id),
            ])
            // Sembunyikan nominal (foto layar): CSS + status di <head>, tombol mata sebelum menu profil
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('partials.sembunyi-uang'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => view('filament.tombol-sembunyi-uang'))
            ->renderHook(PanelsRenderHook::FOOTER, fn () => new HtmlString(
                '<div style="text-align:center;font-size:11px;opacity:.6;padding:1rem 0">'.HakCipta::html().'</div>'
            ))
            ->navigationGroups([
                'Rental',
                'F&B',
                'Sewa Playbox',
                'Karyawan',
                'Pengaturan',
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
                AturBahasa::class,
                HeaderKeamanan::class,
                JagaHakCipta::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                SetTenancy::class,
            ]);
    }
}
