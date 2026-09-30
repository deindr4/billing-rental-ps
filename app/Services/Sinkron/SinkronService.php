<?php

namespace App\Services\Sinkron;

use App\Models\Tenant;
use App\Support\Sinkron\DaftarTabel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * (Server lokal) sinkron dua arah dengan server cloud:
 *   1. dorong: kirim isi sync_antrean (perubahan lokal) ke cloud, lalu hapus dari antrean
 *   2. tarik : ambil perubahan dari cloud sejak kursor terakhir, terapkan di lokal
 *
 * Pengaturan (alamat & token) disimpan di file storage, bukan database, supaya tidak ikut
 * tersinkron dan tetap ada setelah database dipulihkan dari backup.
 */
final class SinkronService
{
    private const FILE_PENGATURAN = 'sync/pengaturan.json';

    private const FILE_STATUS = 'sync/status.json';

    private const BATCH = 500;

    public function __construct(private PaketSinkron $paket, private TerapkanSinkron $terapkan) {}

    public function diCloud(): bool
    {
        return config('app.mode') === 'cloud';
    }

    /* ---------------- Pengaturan & status ---------------- */

    public function pengaturan(): array
    {
        return $this->baca(self::FILE_PENGATURAN) + ['url' => null, 'token' => null, 'aktif' => false, 'tenant_id' => null];
    }

    public function simpanPengaturan(?string $url, ?string $token, bool $aktif, ?string $tenantId = null): void
    {
        $lama = $this->pengaturan();

        $this->tulis(self::FILE_PENGATURAN, [
            'url' => $url ? rtrim(trim($url), '/') : null,
            'token' => $token !== null && $token !== '' ? trim($token) : $lama['token'],
            'aktif' => $aktif,
            'tenant_id' => $tenantId ?? $lama['tenant_id'],
        ]);
    }

    public function status(): array
    {
        return $this->baca(self::FILE_STATUS) + [
            'kursor' => 0, 'tarik_semua' => false, 'terakhir_dorong' => null, 'terakhir_tarik' => null,
            'terakhir_ok' => null, 'error' => null,
        ];
    }

    private function ubahStatus(array $ubah): void
    {
        $this->tulis(self::FILE_STATUS, array_merge($this->status(), $ubah));
    }

    public function siap(): bool
    {
        $p = $this->pengaturan();

        return ! $this->diCloud() && $p['aktif'] && $p['url'] && $p['token'];
    }

    public function jumlahAntrean(): int
    {
        return (int) DB::table('sync_antrean')->count();
    }

    /** Tenant yang disinkronkan server lokal ini (rental ini) */
    public function tenantId(): ?string
    {
        return $this->pengaturan()['tenant_id'] ?? (Tenant::query()->count() === 1 ? Tenant::query()->value('id') : null);
    }

    /* ---------------- Proses ---------------- */

    /** Tes koneksi & token */
    public function tes(?string $url = null, ?string $token = null): array
    {
        $p = $this->pengaturan();
        $res = $this->http($url ?? $p['url'], $token ?? $p['token'])->get('/api/sync/info');

        if ($res->status() === 401) {
            throw new RuntimeException('Token ditolak server cloud.');
        }

        if (! $res->ok()) {
            throw new RuntimeException('Server cloud menjawab HTTP '.$res->status().'.');
        }

        return $res->json();
    }

    /**
     * Dorong lalu tarik. Return ringkasan. Aman dipanggil bersamaan (pakai kunci cache).
     *
     * @return array{dorong:int, tarik:int, ditolak:int}
     */
    public function jalankan(): array
    {
        if (! $this->siap()) {
            throw new RuntimeException($this->diCloud()
                ? 'Server ini adalah server cloud; sinkron dijalankan dari server lokal.'
                : 'Sinkron belum diatur atau belum diaktifkan.');
        }

        $kunci = Cache::lock('sinkron-berjalan', 300);

        if (! $kunci->get()) {
            throw new RuntimeException('Sinkron sedang berjalan.');
        }

        try {
            $dorong = $this->dorong();
            $tarik = $this->tarik();

            $this->ubahStatus(['terakhir_ok' => now()->toIso8601String(), 'error' => null]);

            return ['dorong' => $dorong['jumlah'], 'tarik' => $tarik, 'ditolak' => $dorong['ditolak']];
        } catch (Throwable $e) {
            $this->ubahStatus(['error' => mb_substr($e->getMessage(), 0, 300), 'error_pada' => now()->toIso8601String()]);

            throw $e;
        } finally {
            $kunci->release();
        }
    }

    /** @return array{jumlah:int, ditolak:int} */
    public function dorong(): array
    {
        $tenantId = $this->tenantId();

        if (! $tenantId) {
            throw new RuntimeException('Server ini punya lebih dari satu tenant; pilih tenant yang disinkronkan.');
        }

        $total = 0;
        $ditolak = 0;

        for ($putaran = 0; $putaran < 40; $putaran++) {
            $antrean = DB::table('sync_antrean')->orderBy('id')->limit(self::BATCH)->get();

            if ($antrean->isEmpty()) {
                break;
            }

            $mulai = microtime(true);

            // Hanya data tenant rental ini (super admin platform & tenant lain tidak dikirim)
            $milik = $antrean->filter(fn ($a) => $a->tenant_id === $tenantId);
            $paket = $this->paket->bangun($milik);

            if ($paket !== []) {
                $res = $this->http()->timeout(60)->post('/api/sync/dorong', [
                    'tenant_id' => $tenantId,
                    'perubahan' => $paket,
                ]);

                if (! $res->ok() || ! $res->json('ok')) {
                    $pesan = $res->json('pesan') ?? 'HTTP '.$res->status();
                    $this->log('dorong', 'gagal', 0, $mulai, $pesan);

                    throw new RuntimeException('Kirim ke cloud gagal: '.$pesan);
                }

                $total += (int) $res->json('diterapkan') + (int) $res->json('dilewati');
                $ditolak += count($res->json('ditolak') ?? []);
                $this->log('dorong', 'ok', count($paket), $mulai, $ditolak ? "{$ditolak} ditolak" : null);
            }

            DB::table('sync_antrean')->whereIn('id', $antrean->pluck('id'))->delete();
        }

        $this->ubahStatus(['terakhir_dorong' => now()->toIso8601String()]);

        return ['jumlah' => $total, 'ditolak' => $ditolak];
    }

    public function tarik(): int
    {
        $status = $this->status();
        $kursor = (int) $status['kursor'];
        $semua = (bool) $status['tarik_semua'];
        $total = 0;

        for ($putaran = 0; $putaran < 40; $putaran++) {
            $mulai = microtime(true);
            $res = $this->http()->timeout(60)->get('/api/sync/tarik', ['setelah' => $kursor, 'semua' => $semua ? 1 : 0]);

            if (! $res->ok() || ! $res->json('ok')) {
                $pesan = $res->json('pesan') ?? 'HTTP '.$res->status();
                $this->log('tarik', 'gagal', 0, $mulai, $pesan);

                throw new RuntimeException('Ambil dari cloud gagal: '.$pesan);
            }

            $perubahan = $res->json('perubahan') ?? [];

            if ($perubahan !== []) {
                $hasil = $this->terapkan->terapkan($perubahan);
                $total += $hasil['diterapkan'];
                $this->log('tarik', 'ok', count($perubahan), $mulai, $hasil['dilewati'] ? "{$hasil['dilewati']} dilewati (data lokal lebih baru)" : null);
            }

            $kursor = max($kursor, (int) $res->json('kursor'));
            $this->ubahStatus(['kursor' => $kursor]);

            if (! $res->json('lagi')) {
                break;
            }
        }

        $this->ubahStatus(['terakhir_tarik' => now()->toIso8601String(), 'tarik_semua' => false]);

        return $total;
    }

    /** Masukkan SEMUA data ke antrean (sinkron pertama / kirim ulang penuh) */
    public function kirimUlangSemua(): int
    {
        $jumlah = 0;
        $waktu = now()->format('Y-m-d H:i:s.v');

        foreach (DaftarTabel::BARIS as $t) {
            $jumlah += DB::affectingStatement(
                "INSERT INTO sync_antrean (tabel, row_id, aksi, tenant_id, created_at) SELECT '{$t}', id, 'upsert', tenant_id, ? FROM `{$t}`",
                [$waktu]
            );
        }

        $jumlah += DB::affectingStatement("INSERT INTO sync_antrean (tabel, row_id, aksi, tenant_id, created_at) SELECT 'tenants', id, 'upsert', id, ? FROM tenants", [$waktu]);
        $jumlah += DB::affectingStatement("INSERT INTO sync_antrean (tabel, row_id, aksi, tenant_id, created_at) SELECT 'roles', id, 'upsert', tenant_id, ? FROM roles", [$waktu]);

        foreach (DaftarTabel::PIVOT as $t => [$induk, $tabelInduk]) {
            $tenant = $tabelInduk ? "(SELECT tenant_id FROM {$tabelInduk} WHERE id = p.{$induk})" : 'p.tenant_id';
            $jumlah += DB::affectingStatement(
                "INSERT INTO sync_antrean (tabel, row_id, aksi, tenant_id, created_at) SELECT DISTINCT '{$t}', p.{$induk}, 'pivot', {$tenant}, ? FROM `{$t}` p",
                [$waktu]
            );
        }

        return $jumlah;
    }

    /** Tarik ulang semua perubahan yang ada di cloud (mis. setelah memulihkan backup) */
    public function tarikUlangSemua(): void
    {
        $this->ubahStatus(['kursor' => 0, 'tarik_semua' => true]);
    }

    /* ---------------- Helper ---------------- */

    private function http(?string $url = null, ?string $token = null): PendingRequest
    {
        $p = $this->pengaturan();
        $url ??= $p['url'];
        $token ??= $p['token'];

        if (! $url || ! $token) {
            throw new RuntimeException('Alamat cloud & token sinkron belum diisi.');
        }

        return Http::baseUrl($url)->withToken($token)->acceptJson()->connectTimeout(5)->timeout(20);
    }

    private function log(string $arah, string $status, int $jumlah, float $mulai, ?string $pesan = null): void
    {
        DB::table('sync_log')->insert([
            'arah' => $arah, 'status' => $status, 'jumlah' => $jumlah,
            'durasi_ms' => (int) ((microtime(true) - $mulai) * 1000),
            'pesan' => $pesan ? mb_substr($pesan, 0, 500) : null, 'created_at' => now(),
        ]);
    }

    private function baca(string $file): array
    {
        $disk = Storage::disk('local');

        return $disk->exists($file) ? (json_decode($disk->get($file), true) ?: []) : [];
    }

    private function tulis(string $file, array $isi): void
    {
        Storage::disk('local')->put($file, json_encode($isi, JSON_PRETTY_PRINT));
    }
}
