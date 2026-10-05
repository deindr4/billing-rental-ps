<?php

namespace App\Filament\Widgets;

use App\Models\Cabang;
use App\Models\Sesi;
use App\Models\Shift;
use App\Models\Unit;
use App\Services\LaporanService;
use App\Support\Rupiah;
use App\Support\Tenancy;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Rekap cabang aktif hari ini (dibanding kemarin) + grafik omzet 7 hari.
 */
class RekapHariIni extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return app(Tenancy::class)->cabangId() !== null && (bool) auth()->user()?->can('laporan.lihat');
    }

    protected function getHeading(): ?string
    {
        return 'Rekap hari ini · '.(Cabang::find(app(Tenancy::class)->cabangId())?->nama ?? '-');
    }

    protected function getStats(): array
    {
        $laporan = app(LaporanService::class);
        $hari = $laporan->ringkasan(today(), now());
        $kemarin = $laporan->ringkasan(today()->subDay(), now()->subDay());

        $grafik = collect(range(6, 0))
            ->map(fn ($i) => $laporan->ringkasan(today()->subDays($i), today()->subDays($i)->endOfDay())['omzet_bersih'])
            ->all();

        $selisih = $hari['omzet_bersih'] - $kemarin['omzet_bersih'];
        $persen = $kemarin['omzet_bersih'] > 0 ? round($selisih * 100 / $kemarin['omzet_bersih']) : null;

        $unitAktif = Unit::query()->aktif()->count();
        $dipakai = Sesi::query()->aktif()->count();
        $servis = Unit::query()->aktif()->where('status', Unit::STATUS_SERVIS)->count();

        $shift = Shift::query()->where('status', Shift::STATUS_BUKA)->with('user:id,name')->latest('dibuka_pada')->first();

        // Nominal bisa disembunyikan tombol mata (foto layar)
        $rp = fn (int $n) => Rupiah::html($n);

        return [
            Stat::make('Omzet hari ini', $rp($hari['omzet_bersih']))
                ->description($persen === null ? Rupiah::html('Kemarin ', $kemarin['omzet_bersih']) : ($selisih >= 0 ? '+' : '').$persen.'% dari kemarin (s/d jam ini)')
                ->descriptionIcon($selisih >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($selisih >= 0 ? 'success' : 'danger')
                ->chart($grafik),
            Stat::make('Transaksi', number_format($hari['jumlah_transaksi'], 0, ',', '.'))
                ->description(Rupiah::html('Rata-rata ', $hari['rata_rata'], ' · sewa ', $hari['pendapatan_sewa'], ' · F&B ', $hari['pendapatan_fnb']))
                ->icon('heroicon-o-receipt-percent'),
            Stat::make('Unit terpakai', "{$dipakai} / {$unitAktif}")
                ->description($servis > 0 ? "{$servis} unit servis" : 'Semua unit siap')
                ->descriptionColor($servis > 0 ? 'warning' : 'success')
                ->icon('heroicon-o-tv'),
            Stat::make('Pengeluaran', $rp($hari['beban'] + $hari['belanja_stok']))
                ->description($hari['batal_jumlah'] > 0 ? Rupiah::html("{$hari['batal_jumlah']} transaksi dibatalkan (", $hari['batal_nilai'], ')') : 'Tidak ada pembatalan')
                ->descriptionColor($hari['batal_jumlah'] > 0 ? 'warning' : 'gray')
                ->icon('heroicon-o-banknotes'),
            Stat::make('Top up member', $rp($hari['topup']))
                ->description('Titipan saldo, bukan omzet')
                ->icon('heroicon-o-wallet'),
            Stat::make('Shift', $shift ? 'Buka' : 'Tutup')
                ->description($shift ? 'Sejak '.$shift->dibuka_pada->format('H:i').' · '.$shift->user?->name : 'Belum ada kasir yang membuka kas')
                ->color($shift ? 'success' : 'gray')
                ->icon('heroicon-o-clock'),
        ];
    }
}
