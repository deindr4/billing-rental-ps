<?php

namespace App\Services\Tv;

use App\Events\PerintahTv;
use App\Exceptions\BillingException;
use App\Models\LogTv;
use App\Models\PerangkatTv;
use App\Models\RilisApk;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Remote TV dari aplikasi kasir: layar mati/nyala, volume, restart aplikasi / TV.
 * Perintah dikirim lewat Reverb dan disimpan 2 menit supaya tetap terambil lewat polling.
 */
final class TvRemoteService
{
    public const PERINTAH = [
        'layar_mati' => 'Matikan layar TV',
        'layar_nyala' => 'Nyalakan layar TV',
        'volume_naik' => 'Volume naik',
        'volume_turun' => 'Volume turun',
        'volume_senyap' => 'Senyap / bunyikan',
        'restart_aplikasi' => 'Restart aplikasi TV Agent',
        'restart_tv' => 'Restart TV',
        // Lock: tampilkan & kunci lagi aplikasi TV (setelah unlock / aplikasi ditutup). APK >= 0.5.0
        'kunci' => 'Kunci TV',
        // Tutup aplikasi: TV bebas (layar Google TV) sampai Lock atau sesi berikutnya dimulai. APK >= 0.5.0
        'tutup_aplikasi' => 'Tutup aplikasi TV Agent',
        // TV mengunduh rilis APK terbaru; dipasang saat TV kosong (terkunci), atau langsung untuk versi "paksa"
        'update_aplikasi' => 'Update aplikasi (saat TV kosong)',
        'update_aplikasi_paksa' => 'Update aplikasi sekarang',
    ];

    /** Perintah yang hanya boleh dikirim lewat jalurnya sendiri (PIN di panel TV / admin), bukan remote kartu unit */
    public const PERINTAH_KHUSUS = ['tutup_aplikasi', 'update_aplikasi', 'update_aplikasi_paksa'];

    /** Perintah update APK ke banyak TV (hanya yang versinya belum terbaru). Return jumlah TV yang dikirimi. */
    public function pushUpdate(iterable $perangkat, User $user, bool $paksa = false): int
    {
        $terbaru = RilisApk::aktif()->orderByDesc('versi_kode')->value('versi_nama');

        if (! $terbaru) {
            throw new BillingException('Belum ada rilis APK yang ditawarkan.');
        }

        $n = 0;

        foreach ($perangkat as $p) {
            if ($p->status === PerangkatTv::STATUS_AKTIF && $p->versi_app !== $terbaru) {
                $this->kirim($p, $paksa ? 'update_aplikasi_paksa' : 'update_aplikasi', $user);
                $n++;
            }
        }

        return $n;
    }

    private const SIMPAN_DETIK = 120;

    public function kirim(PerangkatTv $perangkat, string $perintah, User $user): array
    {
        if (! isset(self::PERINTAH[$perintah])) {
            throw new BillingException('Perintah TV tidak dikenal.');
        }

        if ($perangkat->status !== PerangkatTv::STATUS_AKTIF) {
            throw new BillingException('TV sudah dicabut.');
        }

        $data = ['id' => (string) Str::uuid(), 'perintah' => $perintah, 'waktu_ms' => now()->getTimestampMs()];

        $kunci = self::kunci($perangkat->id);
        $antre = collect(Cache::get($kunci, []))
            ->filter(fn ($p) => $p['waktu_ms'] > now()->subSeconds(self::SIMPAN_DETIK)->getTimestampMs())
            ->push($data)
            ->take(-10)
            ->values()
            ->all();

        Cache::put($kunci, $antre, self::SIMPAN_DETIK);

        PerintahTv::dispatch($perangkat->id, $data);

        // Volume tidak dicatat (terlalu sering); aksi daya & restart dicatat
        if (! str_starts_with($perintah, 'volume_')) {
            LogTv::catat($perangkat, 'perintah', ['perintah' => $perintah, 'label' => self::PERINTAH[$perintah]], $user);
        }

        return $data;
    }

    /** Perintah yang belum kedaluwarsa, disertakan di GET /api/tv/status */
    public static function antrean(string $perangkatId): array
    {
        $batas = now()->subSeconds(self::SIMPAN_DETIK)->getTimestampMs();

        return array_values(array_filter(Cache::get(self::kunci($perangkatId), []), fn ($p) => $p['waktu_ms'] > $batas));
    }

    private static function kunci(string $perangkatId): string
    {
        return "tv:perintah:{$perangkatId}";
    }
}
