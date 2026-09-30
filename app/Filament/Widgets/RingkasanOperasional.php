<?php

namespace App\Filament\Widgets;

use App\Models\AuditLog;
use App\Models\Maintenance;
use App\Models\Member;
use App\Models\Produk;
use App\Services\Aset\AsetService;
use App\Services\LaporanService;
use App\Services\StatusSistemService;
use App\Support\Tenancy;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Hal yang perlu perhatian: TV, maintenance, stok, anomali, member, laba bulan ini.
 */
class RingkasanOperasional extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected ?string $pollingInterval = '60s';

    protected ?string $heading = 'Perlu perhatian';

    public static function canView(): bool
    {
        return app(Tenancy::class)->cabangId() !== null;
    }

    protected function getStats(): array
    {
        $tv = app(StatusSistemService::class)->tv();

        $mtTerbuka = Maintenance::query()->terbuka()->count();
        $mtDikerjakan = Maintenance::query()->where('status', 'dikerjakan')->count();
        $jatuhTempo = app(AsetService::class)->jatuhTempoServis()->count();

        $menipis = Produk::query()->aktif()->where('lacak_stok', true)->with('stok:id,produk_id,qty')->get()
            ->filter(fn (Produk $p) => $p->stokMenipis())->count();

        $anomali = AuditLog::query()->where('anomali', true)->where('created_at', '>=', today())
            ->where(fn ($q) => $q->where('cabang_id', app(Tenancy::class)->cabangId())->orWhereNull('cabang_id'))
            ->count();

        $member = Member::query()->aktif()->count();
        $memberBaru = Member::query()->where('created_at', '>=', now()->startOfMonth())->count();

        $stats = [
            Stat::make('TV Agent', $tv['nilai'])
                ->description($tv['detail'])
                ->descriptionColor(match ($tv['status']) {
                    'ok' => 'success', 'mati' => 'danger', 'peringatan' => 'warning', default => 'gray'
                })
                ->icon('heroicon-o-computer-desktop'),
            Stat::make('Maintenance', "{$mtTerbuka} terbuka")
                ->description("{$mtDikerjakan} dikerjakan".($jatuhTempo > 0 ? " · {$jatuhTempo} servis jatuh tempo" : ''))
                ->descriptionColor($jatuhTempo > 0 || $mtDikerjakan > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-wrench-screwdriver')
                ->url(route('maintenance')),
            Stat::make('Stok menipis', (string) $menipis)
                ->description($menipis > 0 ? 'Produk di bawah stok minimum' : 'Stok aman')
                ->descriptionColor($menipis > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-cube')
                ->url(route('stok')),
            Stat::make('Anomali hari ini', (string) $anomali)
                ->description('Void, gratis, bypass TV, PIN salah, selisih kas')
                ->descriptionColor($anomali > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-shield-exclamation')
                ->url(route('laporan')),
            Stat::make('Member aktif', number_format($member, 0, ',', '.'))
                ->description("{$memberBaru} baru bulan ini")
                ->icon('heroicon-o-identification')
                ->url(route('member')),
        ];

        if (auth()->user()?->can('laporan.laba')) {
            $bulan = app(LaporanService::class)->ringkasan(now()->startOfMonth(), now());

            $stats[] = Stat::make('Laba bersih bulan ini', 'Rp '.number_format($bulan['laba_bersih'], 0, ',', '.'))
                ->description('Omzet Rp '.number_format($bulan['omzet_bersih'], 0, ',', '.').' · beban Rp '.number_format($bulan['beban'], 0, ',', '.'))
                ->color($bulan['laba_bersih'] >= 0 ? 'success' : 'danger')
                ->icon('heroicon-o-chart-bar');
        }

        return $stats;
    }
}
