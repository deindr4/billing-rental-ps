<?php

namespace App\Services\Tv;

use App\Models\LogTv;
use App\Models\PerangkatTv;
use App\Models\User;
use App\Support\Audit;

/**
 * Bypass: membuka TV sementara tanpa sesi (owner nonton YouTube, tes, servis ringan). Dipakai dari TV
 * (ketik PIN) maupun dari panel operator. Persetujuan PIN dilakukan pemanggil sebelum memanggil service ini.
 */
final class BypassTvService
{
    public function __construct(private StatusTvService $status) {}

    /** Durasi dibatasi 5 menit s/d batas maksimal cabang; kosong = durasi default unit/cabang */
    private function rapikanMenit(PerangkatTv $perangkat, ?int $menit): int
    {
        $maks = $this->status->bypassMaks($perangkat->cabang_id);

        return max(5, min($maks, $menit ?: $this->status->durasiBypass($perangkat)));
    }

    public function mulai(PerangkatTv $perangkat, User $penyetuju, string $lewat, ?User $pemohon = null, ?int $menit = null): PerangkatTv
    {
        $menit = $this->rapikanMenit($perangkat, $menit);

        $perangkat->update(['bypass_sampai' => now()->addMinutes($menit)]);

        LogTv::catat($perangkat, 'bypass', array_filter([
            'menit' => $menit,
            'lewat' => $lewat,
            'pemohon' => $pemohon && $pemohon->id !== $penyetuju->id ? $pemohon->name : null,
        ]), $penyetuju);

        NotifikasiTv::perangkat($perangkat, 'bypass');

        Audit::catat('bypass_tv', "Bypass TV {$perangkat->unit?->nama} {$menit} menit (disetujui {$penyetuju->name})", $perangkat, [
            'menit' => $menit, 'lewat' => $lewat, 'penyetuju' => $penyetuju->name,
        ], userId: $pemohon?->id ?? $penyetuju->id);

        return $perangkat;
    }

    /** Tambah waktu bypass yang sedang berjalan; sisa waktu tidak boleh melebihi batas maksimal */
    public function perpanjang(PerangkatTv $perangkat, User $penyetuju, int $menit, string $lewat, ?User $pemohon = null): PerangkatTv
    {
        if (! $perangkat->sedangBypass()) {
            return $this->mulai($perangkat, $penyetuju, $lewat, $pemohon, $menit);
        }

        $maks = $this->status->bypassMaks($perangkat->cabang_id);
        $sampai = $perangkat->bypass_sampai->copy()->addMinutes(max(5, $menit));

        if ($sampai->gt(now()->addMinutes($maks))) {
            $sampai = now()->addMinutes($maks);
        }

        $perangkat->update(['bypass_sampai' => $sampai]);

        LogTv::catat($perangkat, 'bypass', array_filter([
            'menit' => $menit,
            'perpanjang' => true,
            'lewat' => $lewat,
            'pemohon' => $pemohon && $pemohon->id !== $penyetuju->id ? $pemohon->name : null,
        ]), $penyetuju);

        NotifikasiTv::perangkat($perangkat, 'bypass');

        Audit::catat('bypass_tv', "Bypass TV {$perangkat->unit?->nama} {$menit} menit (disetujui {$penyetuju->name})", $perangkat, [
            'menit' => $menit, 'lewat' => $lewat, 'penyetuju' => $penyetuju->name,
        ], userId: $pemohon?->id ?? $penyetuju->id);

        return $perangkat;
    }

    public function akhiri(PerangkatTv $perangkat, string $lewat, ?User $user = null): PerangkatTv
    {
        if ($perangkat->sedangBypass()) {
            $perangkat->update(['bypass_sampai' => null]);

            LogTv::catat($perangkat, 'bypass_akhir', ['lewat' => $lewat], $user);
            NotifikasiTv::perangkat($perangkat, 'bypass_akhir');
        }

        return $perangkat;
    }
}
