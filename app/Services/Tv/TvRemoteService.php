<?php

namespace App\Services\Tv;

use App\Events\PerintahTv;
use App\Exceptions\BillingException;
use App\Models\LogTv;
use App\Models\PerangkatTv;
use App\Models\RilisApk;
use App\Models\User;
use App\Support\PemberitahuanTv;
use App\Support\PengaturanPc;
use App\Support\WakeOnLan;
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
        'layar_nyala' => 'Bangunkan TV',
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
        // Pemberitahuan di tengah layar (isi di "data": teks, detik, ukuran, huruf, tebal). APK >= 0.6.0
        'pemberitahuan' => 'Pemberitahuan',
        // Pindah ke input HDMI lain (isi di "data": id, label); TV terkunci cukup menyimpan pilihan. APK >= 0.6.1
        'pindah_hdmi' => 'Pindah HDMI',
        // ---- Agen kiosk PC Windows (perangkat jenis pc), lihat docs/pc-agent.md ----
        // Paksa tutup aplikasi/game yang sedang di depan (game hang), kembali ke kiosk; sesi tetap berjalan
        'tutup_game' => 'Tutup game (hang)',
        // Task Manager boleh dibuka sementara (isi di "data": menit) lalu diblok lagi
        'izin_task_manager' => 'Izinkan Task Manager sementara',
        'logoff_pc' => 'Log off akun pemain',
        'restart_pc' => 'Restart PC',
        'matikan_pc' => 'Matikan PC',
        // Diteruskan ke PC lain yang menyala: kirim paket Wake-on-LAN ke MAC di "data" (server tanpa ekstensi sockets)
        'bangunkan_pc' => 'Nyalakan PC (Wake-on-LAN)',
    ];

    /** Perintah khusus PC: ditolak untuk TV */
    public const PERINTAH_PC = ['tutup_game', 'izin_task_manager', 'logoff_pc', 'restart_pc', 'matikan_pc', 'bangunkan_pc'];

    /** Perintah TV yang tidak berlaku di PC */
    public const PERINTAH_TV_SAJA = ['layar_mati', 'layar_nyala', 'volume_naik', 'volume_turun', 'volume_senyap', 'restart_tv', 'pindah_hdmi', 'update_aplikasi', 'update_aplikasi_paksa'];

    /** Perintah yang hanya boleh dikirim lewat jalurnya sendiri (PIN di panel TV / admin), bukan remote kartu unit */
    public const PERINTAH_KHUSUS = ['tutup_aplikasi', 'update_aplikasi', 'update_aplikasi_paksa', 'pemberitahuan', 'pindah_hdmi', 'izin_task_manager', 'bangunkan_pc'];

    /** Perintah update APK ke banyak TV (hanya yang versinya belum terbaru). Return jumlah TV yang dikirimi. */
    public function pushUpdate(iterable $perangkat, User $user, bool $paksa = false): int
    {
        $terbaru = RilisApk::aktif()->orderByDesc('versi_kode')->value('versi_nama');

        if (! $terbaru) {
            throw new BillingException('Belum ada rilis APK yang ditawarkan.');
        }

        $n = 0;

        foreach ($perangkat as $p) {
            // Rilis APK hanya untuk TV; agen PC diperbarui terpisah
            if ($p->status === PerangkatTv::STATUS_AKTIF && ! $p->isPc() && $p->versi_app !== $terbaru) {
                $this->kirim($p, $paksa ? 'update_aplikasi_paksa' : 'update_aplikasi', $user);
                $n++;
            }
        }

        return $n;
    }

    private const SIMPAN_DETIK = 120;

    /** Bangunkan: TV standby baru mengambil perintah saat jaringannya aktif lagi, jadi disimpan lebih lama (APK >= 0.6.6) */
    private const SIMPAN_BANGUN_DETIK = 600;

    private static function masihBerlaku(array $p): bool
    {
        $detik = $p['perintah'] === 'layar_nyala' ? self::SIMPAN_BANGUN_DETIK : self::SIMPAN_DETIK;

        return $p['waktu_ms'] > now()->subSeconds($detik)->getTimestampMs();
    }

    /** Pemberitahuan di tengah layar ke satu / banyak TV. Return jumlah TV yang dikirimi. */
    public function pemberitahuan(iterable $perangkat, array $isi, User $user): int
    {
        $isi = PemberitahuanTv::rapikan($isi);
        $n = 0;

        foreach ($perangkat as $p) {
            if ($p->status === PerangkatTv::STATUS_AKTIF) {
                $this->kirim($p, 'pemberitahuan', $user, $isi);
                $n++;
            }
        }

        return $n;
    }

    /** @param array|null $isi data tambahan perintah (mis. isi pemberitahuan) */
    public function kirim(PerangkatTv $perangkat, string $perintah, User $user, ?array $isi = null): array
    {
        if (! isset(self::PERINTAH[$perintah])) {
            throw new BillingException('Perintah TV tidak dikenal.');
        }

        if ($perangkat->status !== PerangkatTv::STATUS_AKTIF) {
            throw new BillingException(($perangkat->isPc() ? 'PC' : 'TV').' sudah dicabut.');
        }

        if ($perangkat->isPc() ? in_array($perintah, self::PERINTAH_TV_SAJA, true) : in_array($perintah, self::PERINTAH_PC, true)) {
            throw new BillingException('Perintah ini tidak berlaku untuk '.($perangkat->isPc() ? 'PC' : 'TV').'.');
        }

        $data = ['id' => (string) Str::uuid(), 'perintah' => $perintah, 'waktu_ms' => now()->getTimestampMs()];

        if ($isi !== null) {
            $data['data'] = $isi;
        }

        $kunci = self::kunci($perangkat->id);
        $antre = collect(Cache::get($kunci, []))
            ->filter(fn ($p) => self::masihBerlaku($p))
            ->push($data)
            ->take(-10)
            ->values()
            ->all();

        Cache::put($kunci, $antre, self::SIMPAN_BANGUN_DETIK);

        PerintahTv::dispatch($perangkat->id, $data);

        // Volume tidak dicatat (terlalu sering); pindah HDMI sudah dicatat sendiri (input_hdmi); aksi daya & restart dicatat
        if (! str_starts_with($perintah, 'volume_') && $perintah !== 'pindah_hdmi') {
            LogTv::catat($perangkat, 'perintah', array_filter([
                'perintah' => $perintah,
                'label' => self::PERINTAH[$perintah],
                'teks' => $isi['teks'] ?? null,
            ]), $user);
        }

        return $data;
    }

    /** Task Manager boleh dibuka di PC selama menit pengaturan cabang (game hang/crash) */
    public function izinTaskManager(PerangkatTv $pc, User $user): int
    {
        $menit = PengaturanPc::ambil($pc->cabang_id)['task_manager_menit'];
        $this->kirim($pc, 'izin_task_manager', $user, ['menit' => $menit]);

        return $menit;
    }

    /**
     * Nyalakan PC yang mati lewat Wake-on-LAN. Server lokal mengirim paket sendiri (butuh ekstensi sockets);
     * selain itu dititipkan ke PC lain di cabang yang sedang menyala (satu LAN). Return keterangan jalur.
     */
    public function bangunkanPc(PerangkatTv $pc, User $user): string
    {
        if (! $pc->mac) {
            throw new BillingException('Alamat MAC PC belum diketahui. Nyalakan PC sekali agar agen melapor.');
        }

        if (config('app.mode') !== 'cloud' && WakeOnLan::kirim($pc->mac)) {
            LogTv::catat($pc, 'perintah', ['perintah' => 'bangunkan_pc', 'label' => self::PERINTAH['bangunkan_pc']], $user);

            return 'server';
        }

        $perantara = PerangkatTv::aktif()->where('jenis', PerangkatTv::JENIS_PC)
            ->where('cabang_id', $pc->cabang_id)->whereKeyNot($pc->id)
            ->where('terakhir_online', '>=', now()->subSeconds(PerangkatTv::BATAS_ONLINE_DETIK))
            ->first();

        if (! $perantara) {
            throw new BillingException('Tidak ada PC lain yang menyala untuk mengirim Wake-on-LAN. Nyalakan PC secara manual.');
        }

        $this->kirim($perantara, 'bangunkan_pc', $user, ['mac' => $pc->mac, 'untuk' => $pc->namaTampil()]);

        return $perantara->namaTampil();
    }

    /** Perintah yang belum kedaluwarsa, disertakan di GET /api/tv/status */
    public static function antrean(string $perangkatId): array
    {
        return array_values(array_filter(Cache::get(self::kunci($perangkatId), []), fn ($p) => self::masihBerlaku($p)));
    }

    private static function kunci(string $perangkatId): string
    {
        return "tv:perintah:{$perangkatId}";
    }
}
