<?php

namespace App\Livewire\Concerns;

use App\Services\LaporanService;
use Illuminate\Support\Carbon;

/**
 * Pilihan periode laporan + penyusunan data laporan.
 * Dipakai halaman Laporan operator dan admin.
 */
trait PeriodeLaporan
{
    public string $periode = 'hari_ini';

    public string $dari = '';

    public string $sampai = '';

    public static function daftarPeriode(): array
    {
        return [
            'hari_ini' => 'Hari ini',
            'kemarin' => 'Kemarin',
            '7_hari' => '7 hari',
            'bulan_ini' => 'Bulan ini',
            'bulan_lalu' => 'Bulan lalu',
            'custom' => 'Pilih tanggal',
        ];
    }

    protected function siapkanPeriode(): void
    {
        if (! array_key_exists($this->periode, self::daftarPeriode())) {
            $this->periode = 'hari_ini';
        }

        if ($this->periode !== 'custom') {
            $this->terapkanPeriode($this->periode);
        }
    }

    public function pilihPeriode(string $periode): void
    {
        if (array_key_exists($periode, self::daftarPeriode())) {
            $this->periode = $periode;

            if ($periode !== 'custom') {
                $this->terapkanPeriode($periode);
            }
        }
    }

    protected function terapkanPeriode(string $periode): void
    {
        [$dari, $sampai] = match ($periode) {
            'kemarin' => [today()->subDay(), today()->subDay()],
            '7_hari' => [today()->subDays(6), today()],
            'bulan_ini' => [today()->startOfMonth(), today()],
            'bulan_lalu' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()],
            default => [today(), today()],
        };

        $this->dari = $dari->toDateString();
        $this->sampai = $sampai->toDateString();
    }

    /** @return array{0:Carbon,1:Carbon} */
    protected function rentang(): array
    {
        $dari = rescue(fn () => Carbon::createFromFormat('Y-m-d', $this->dari), null, false) ?? today();
        $sampai = rescue(fn () => Carbon::createFromFormat('Y-m-d', $this->sampai), null, false) ?? today();

        if ($sampai->lt($dari)) {
            [$dari, $sampai] = [$sampai, $dari];
        }

        return [$dari->copy()->startOfDay(), $sampai->copy()->endOfDay()];
    }

    /** Semua data untuk partial laporan.isi (mengikuti scope tenant & cabang yang aktif) */
    protected function dataLaporan(LaporanService $laporan): array
    {
        [$dari, $sampai] = $this->rentang();

        // Periode pembanding: panjang sama, tepat sebelum periode ini
        $hari = (int) $dari->diffInDays($sampai) + 1;
        $dariLalu = $dari->copy()->subDays($hari);
        $sampaiLalu = $dari->copy()->subSecond();

        $perUnit = $laporan->perUnit($dari, $sampai);

        return [
            'dariTgl' => $dari,
            'sampaiTgl' => $sampai,
            'hari' => $hari,
            'r' => $laporan->ringkasan($dari, $sampai),
            'lalu' => $laporan->ringkasan($dariLalu, $sampaiLalu),
            'perMetode' => $laporan->perMetode($dari, $sampai),
            'jamSibuk' => $laporan->jamSibuk($dari, $sampai),
            'perUnit' => $perUnit,
            'maksUnit' => max(1, (int) $perUnit->max('detik')),
            'produk' => $laporan->produkTerlaris($dari, $sampai),
            'operator' => $laporan->perOperator($dari, $sampai),
            'pengeluaran' => $laporan->pengeluaranPerKategori($dari, $sampai),
            'lihatLaba' => (bool) auth()->user()?->can('laporan.laba'),
            'anomali' => $laporan->anomali($dari, $sampai),
        ];
    }
}
