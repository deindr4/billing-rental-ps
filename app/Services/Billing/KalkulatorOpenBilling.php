<?php

namespace App\Services\Billing;

/**
 * Perhitungan biaya open billing.
 *
 * - Lama main <= toleransi  -> tidak ditagih (misal salah unit / batal main)
 * - Selain itu dibulatkan ke atas per blok menit, minimal sebesar minimal menit
 * - Biaya = tarif per jam x menit ditagih / 60 (dibulatkan ke atas),
 *   lalu dibulatkan ke atas ke kelipatan pembulatan rupiah (jika diisi)
 */
final class KalkulatorOpenBilling
{
    /**
     * @return array{menit_aktual:int, menit_ditagih:int, biaya:int}
     */
    public function hitung(
        int $tarifPerJam,
        int $durasiDetik,
        int $blokMenit = 15,
        int $toleransiMenit = 5,
        int $minimalMenit = 60,
        int $pembulatanRupiah = 0,
    ): array {
        $menitAktual = intdiv(max(0, $durasiDetik) + 59, 60);

        if ($menitAktual <= $toleransiMenit) {
            return ['menit_aktual' => $menitAktual, 'menit_ditagih' => 0, 'biaya' => 0];
        }

        $blok = max(1, $blokMenit);
        $menitDitagih = intdiv($menitAktual + $blok - 1, $blok) * $blok;
        $menitDitagih = max($menitDitagih, $minimalMenit);

        $biaya = intdiv($tarifPerJam * $menitDitagih + 59, 60);

        if ($pembulatanRupiah > 0) {
            $biaya = intdiv($biaya + $pembulatanRupiah - 1, $pembulatanRupiah) * $pembulatanRupiah;
        }

        return ['menit_aktual' => $menitAktual, 'menit_ditagih' => $menitDitagih, 'biaya' => $biaya];
    }
}
