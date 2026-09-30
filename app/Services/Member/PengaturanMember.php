<?php

namespace App\Services\Member;

use App\Models\Pengaturan;

/**
 * Aturan program member (tingkat tenant). Nilai bawaan dipakai jika owner belum mengatur.
 */
final class PengaturanMember
{
    public const TIER_BAWAAN = [
        ['nama' => 'Reguler', 'min_belanja' => 0, 'diskon_persen' => 0],
        ['nama' => 'Silver', 'min_belanja' => 500_000, 'diskon_persen' => 5],
        ['nama' => 'Gold', 'min_belanja' => 1_500_000, 'diskon_persen' => 10],
        ['nama' => 'Platinum', 'min_belanja' => 5_000_000, 'diskon_persen' => 15],
    ];

    public function aktif(): bool
    {
        return (bool) $this->ambil('member.aktif', true);
    }

    /** @return array<int, array{nama:string, min_belanja:int, diskon_persen:int}> urut dari min_belanja terkecil */
    public function tier(): array
    {
        $tier = collect($this->ambil('member.tier', self::TIER_BAWAAN) ?: self::TIER_BAWAAN)
            ->map(fn ($t) => [
                'nama' => (string) ($t['nama'] ?? 'Reguler'),
                'min_belanja' => max(0, (int) ($t['min_belanja'] ?? 0)),
                'diskon_persen' => max(0, min(100, (int) ($t['diskon_persen'] ?? 0))),
            ])
            ->sortBy('min_belanja')
            ->values()
            ->all();

        return $tier ?: self::TIER_BAWAAN;
    }

    /** Rupiah belanja untuk 1 poin (0 = poin nonaktif) */
    public function belanjaPerPoin(): int
    {
        return max(0, (int) $this->ambil('member.belanja_per_poin', 1000));
    }

    /** Nilai 1 poin saat ditukar (Rp) */
    public function nilaiPoin(): int
    {
        return max(0, (int) $this->ambil('member.nilai_poin', 100));
    }

    /** Minimal poin sekali tukar */
    public function minTukarPoin(): int
    {
        return max(1, (int) $this->ambil('member.min_tukar_poin', 50));
    }

    /** Jumlah stamp untuk 1 hadiah (0 = stamp nonaktif) */
    public function targetStamp(): int
    {
        return max(0, (int) $this->ambil('member.target_stamp', 10));
    }

    /** Hadiah stamp: menit main gratis */
    public function hadiahStampMenit(): int
    {
        return max(0, (int) $this->ambil('member.hadiah_stamp_menit', 60));
    }

    /** Minimal belanja per transaksi billing agar dapat 1 stamp */
    public function minBelanjaStamp(): int
    {
        return max(0, (int) $this->ambil('member.min_belanja_stamp', 0));
    }

    /** @return array<int, array{min:int, bonus:int}> bonus top up, urut dari min terbesar */
    public function bonusTopUp(): array
    {
        return collect($this->ambil('member.bonus_topup', []) ?: [])
            ->map(fn ($b) => ['min' => max(0, (int) ($b['min'] ?? 0)), 'bonus' => max(0, (int) ($b['bonus'] ?? 0))])
            ->filter(fn ($b) => $b['min'] > 0 && $b['bonus'] > 0)
            ->sortByDesc('min')
            ->values()
            ->all();
    }

    public function minTopUp(): int
    {
        return max(1000, (int) $this->ambil('member.min_topup', 10_000));
    }

    private function ambil(string $kunci, mixed $default): mixed
    {
        // Aturan member berlaku untuk seluruh tenant (tidak per cabang)
        return Pengaturan::query()->where('kunci', $kunci)->whereNull('cabang_id')->first()?->nilai['v'] ?? $default;
    }
}
