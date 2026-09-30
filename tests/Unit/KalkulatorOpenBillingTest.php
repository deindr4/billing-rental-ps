<?php

namespace Tests\Unit;

use App\Services\Billing\KalkulatorOpenBilling;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KalkulatorOpenBillingTest extends TestCase
{
    /** [tarif/jam, detik main, menit ditagih, biaya] — blok 15, toleransi 5, minimal 60 */
    public static function kasus(): array
    {
        return [
            'dalam toleransi' => [8000, 3 * 60, 0, 0],
            'tepat toleransi' => [8000, 5 * 60, 0, 0],
            'lewat toleransi kena minimal' => [8000, 6 * 60, 60, 8000],
            '40 menit kena minimal' => [8000, 40 * 60, 60, 8000],
            'tepat 60 menit' => [8000, 60 * 60, 60, 8000],
            '61 menit jadi 75' => [8000, 61 * 60, 75, 10000],
            'tepat 75 menit' => [8000, 75 * 60, 75, 10000],
            '76 menit jadi 90' => [8000, 76 * 60, 90, 12000],
            'lebih 1 detik dibulatkan' => [8000, 60 * 60 + 1, 75, 10000],
            'PS5 2 jam 10 menit' => [16000, 130 * 60, 135, 36000],
        ];
    }

    #[DataProvider('kasus')]
    public function test_perhitungan_open_billing(int $tarif, int $detik, int $menitDitagih, int $biaya): void
    {
        $hasil = (new KalkulatorOpenBilling)->hitung($tarif, $detik, 15, 5, 60);

        $this->assertSame($menitDitagih, $hasil['menit_ditagih']);
        $this->assertSame($biaya, $hasil['biaya']);
    }

    public function test_pembulatan_rupiah(): void
    {
        // 7000/jam x 75 menit = 8750 -> dibulatkan ke 9000
        $hasil = (new KalkulatorOpenBilling)->hitung(7000, 61 * 60, 15, 5, 60, 500);

        $this->assertSame(9000, $hasil['biaya']);
    }
}
