<?php

namespace App\Services\Karyawan;

use App\Exceptions\BillingException;
use App\Models\Absensi;
use App\Models\JadwalKaryawan;
use App\Models\Karyawan;
use App\Models\TemplateShift;
use App\Support\Audit;
use App\Support\Gambar;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Absen masuk / pulang karyawan dengan PIN + foto selfie (diambil kamera tablet kasir).
 * Terlambat = masuk melewati jadwal mulai + toleransi; pulang cepat = pulang sebelum jadwal selesai.
 * Masuk tanpa jadwal hari itu tetap dicatat (lembur / tukar shift), tanpa hitungan terlambat.
 */
final class AbsensiService
{
    private const MAKS_PIN = 5;

    private const BLOKIR_DETIK = 300;

    /** Template shift karyawan pada tanggal itu (null = libur / belum dijadwalkan) */
    public function jadwalHari(Karyawan $k, CarbonInterface $tanggal): ?TemplateShift
    {
        return JadwalKaryawan::query()->with('template')
            ->where('karyawan_id', $k->id)->where('hari', $tanggal->dayOfWeek)
            ->first()?->template;
    }

    /** Absensi yang belum ditutup (sudah masuk, belum pulang) */
    public function terbuka(Karyawan $k): ?Absensi
    {
        return Absensi::withoutGlobalScopes()->where('karyawan_id', $k->id)->whereNull('pulang_pada')->latest('masuk_pada')->first();
    }

    public function verifikasiPin(Karyawan $k, ?string $pin): void
    {
        $kunci = 'pin-absen:'.$k->tenant_id.'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($kunci, self::MAKS_PIN)) {
            throw new BillingException('Terlalu banyak PIN salah. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.');
        }

        if (! $k->hashPin()) {
            throw new BillingException("{$k->nama} belum punya PIN absen. Atur di Admin → Karyawan.");
        }

        if (! preg_match('/^\d{4,6}$/', trim((string) $pin)) || ! $k->cocokPin(trim((string) $pin))) {
            RateLimiter::hit($kunci, self::BLOKIR_DETIK);
            Audit::catat('pin_gagal', "PIN absen {$k->nama} salah", $k, tenantId: $k->tenant_id);

            throw new BillingException('PIN salah.');
        }

        RateLimiter::clear($kunci);
    }

    /** @param  string  $fotoSumber  path file foto (unggahan sementara) */
    public function masuk(Karyawan $k, ?string $pin, string $fotoSumber, string $cabangId): Absensi
    {
        $k = Karyawan::withoutGlobalScopes()->findOrFail($k->id); // status aktif terbaru

        if (! $k->is_active) {
            throw new BillingException("{$k->nama} tidak aktif bekerja.");
        }

        $this->verifikasiPin($k, $pin);

        return DB::transaction(function () use ($k, $fotoSumber, $cabangId) {
            Karyawan::withoutGlobalScopes()->whereKey($k->id)->lockForUpdate()->first();

            if ($buka = $this->terbuka($k)) {
                throw new BillingException("{$k->nama} sudah absen masuk pukul {$buka->masuk_pada->format('H:i')}. Absen pulang dulu.");
            }

            $sekarang = now();
            $template = $this->jadwalHari($k, $sekarang);
            [$mulai, $selesai] = $template ? $template->rentang($sekarang) : [null, null];
            $terlambat = $mulai && $sekarang->gt($mulai->copy()->addMinutes($template->toleransi_menit))
                ? (int) $mulai->diffInMinutes($sekarang) : 0;

            return Absensi::create([
                'tenant_id' => $k->tenant_id,
                'cabang_id' => $cabangId,
                'karyawan_id' => $k->id,
                'tanggal' => $sekarang->toDateString(),
                'masuk_pada' => $sekarang,
                'foto_masuk' => $this->simpanFoto($fotoSumber, $k->tenant_id),
                'template_shift_id' => $template?->id,
                'jadwal_mulai' => $mulai,
                'jadwal_selesai' => $selesai,
                'terlambat_menit' => $terlambat,
            ]);
        });
    }

    public function pulang(Karyawan $k, ?string $pin, string $fotoSumber): Absensi
    {
        $this->verifikasiPin($k, $pin);

        return DB::transaction(function () use ($k, $fotoSumber) {
            $a = $this->terbuka($k) ?? throw new BillingException("{$k->nama} belum absen masuk.");
            $a = Absensi::withoutGlobalScopes()->whereKey($a->id)->lockForUpdate()->firstOrFail();
            $sekarang = now();

            $a->update([
                'pulang_pada' => $sekarang,
                'foto_pulang' => $this->simpanFoto($fotoSumber, $k->tenant_id),
                'menit_kerja' => (int) $a->masuk_pada->diffInMinutes($sekarang),
                'pulang_cepat_menit' => $a->jadwal_selesai && $sekarang->lt($a->jadwal_selesai)
                    ? (int) $sekarang->diffInMinutes($a->jadwal_selesai) : 0,
            ]);

            return $a;
        });
    }

    /** Rekap kehadiran satu periode (dipakai rekap gaji) */
    public function rekap(Karyawan $k, CarbonInterface $dari, CarbonInterface $sampai): array
    {
        $baris = Absensi::withoutGlobalScopes()->where('karyawan_id', $k->id)
            ->whereBetween('tanggal', [$dari->toDateString(), $sampai->toDateString()])->get();

        return [
            'hari_hadir' => $baris->pluck('tanggal')->map(fn ($t) => $t->toDateString())->unique()->count(),
            'menit_kerja' => (int) $baris->sum('menit_kerja'),
            'terlambat_kali' => $baris->where('terlambat_menit', '>', 0)->count(),
            'terlambat_menit' => (int) $baris->sum('terlambat_menit'),
            'belum_pulang' => $baris->whereNull('pulang_pada')->count(),
        ];
    }

    private function simpanFoto(string $sumber, string $tenantId): string
    {
        return Gambar::simpanWebp($sumber, "tenants/{$tenantId}/absensi/".now()->format('Ym'), 640, 65);
    }
}
