<?php

namespace App\Services;

use App\Models\Pengaturan;
use App\Models\PerangkatTv;
use App\Models\ServerSinkron;
use App\Services\Sinkron\SinkronService;
use App\Services\Tv\StatusTvService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Number;
use Throwable;

/**
 * Status kesehatan sistem untuk dasbor admin.
 * Setiap item: ['status' => ok|peringatan|mati|info, 'judul', 'nilai', 'detail'].
 * Hasil yang mahal (ping cloud, ukuran DB) di-cache 60 detik.
 */
final class StatusSistemService
{
    public const CACHE = 'status-sistem:';

    public function semua(): array
    {
        return [
            'server' => $this->server(),
            'database' => $this->database(),
            'cloud' => $this->cloud(),
            'sync' => $this->sync(),
            'antrean' => $this->antrean(),
            'realtime' => $this->realtime(),
            'backup' => $this->backup(),
            'tv' => $this->tv(),
            'versi' => $this->versi(),
        ];
    }

    /** Versi aplikasi & update dari GitHub (hasil cek terakhir; tidak menunggu internet) */
    public function versi(): array
    {
        $u = app(UpdateAplikasi::class);
        $r = $u->terakhir();
        $sekarang = UpdateAplikasi::versiSekarang();

        return match (true) {
            $u->repo() === null => ['status' => 'info', 'judul' => 'Versi aplikasi', 'nilai' => $sekarang, 'detail' => 'Cek update dimatikan'],
            $r === null => ['status' => 'info', 'judul' => 'Versi aplikasi', 'nilai' => $sekarang, 'detail' => 'Belum dicek — Pemeliharaan → Cek update'],
            isset($r['error']) => ['status' => 'info', 'judul' => 'Versi aplikasi', 'nilai' => $sekarang, 'detail' => $r['error']],
            $r['baru'] => ['status' => 'peringatan', 'judul' => 'Versi aplikasi', 'nilai' => "Update {$r['versi']} tersedia",
                'detail' => "Terpasang {$sekarang} · buka Pemeliharaan sistem untuk catatan & unduhan"],
            default => ['status' => 'ok', 'judul' => 'Versi aplikasi', 'nilai' => $sekarang, 'detail' => 'Terbaru'],
        };
    }

    public function lupakan(): void
    {
        foreach (['database', 'cloud', 'realtime', 'sync'] as $k) {
            Cache::forget(self::CACHE.$k);
        }
    }

    public function server(): array
    {
        $mode = config('app.mode') === 'cloud' ? 'Cloud (VPS)' : 'Lokal (LAN)';
        $bebas = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());
        $persenBebas = $bebas && $total ? (int) round($bebas * 100 / $total) : null;

        return [
            'status' => $persenBebas !== null && $persenBebas < 10 ? 'peringatan' : 'ok',
            'judul' => 'Server aplikasi',
            'nilai' => $mode,
            'detail' => sprintf(
                'PHP %s · Laravel %s · %s%s',
                PHP_VERSION,
                app()->version(),
                app()->environment(),
                $bebas ? ' · disk kosong '.Number::fileSize($bebas, 1).($persenBebas !== null ? " ({$persenBebas}%)" : '') : ''
            ),
        ];
    }

    public function database(): array
    {
        return Cache::remember(self::CACHE.'database', 60, function () {
            try {
                $mulai = microtime(true);
                DB::select('SELECT 1');
                $ms = (int) round((microtime(true) - $mulai) * 1000);

                $nama = DB::connection()->getDatabaseName();
                $ukuran = (int) DB::table('information_schema.tables')->where('table_schema', $nama)
                    ->sum(DB::raw('data_length + index_length'));
                $versi = DB::selectOne('SELECT VERSION() as v')->v ?? '-';

                return [
                    'status' => 'ok',
                    'judul' => 'Database',
                    'nilai' => "Terhubung ({$ms} ms)",
                    'detail' => "{$nama} · ".Number::fileSize($ukuran, 1).' · '.(str_contains($versi, 'MariaDB') ? $versi : "MySQL {$versi}"),
                ];
            } catch (Throwable $e) {
                return ['status' => 'mati', 'judul' => 'Database', 'nilai' => 'Tidak terhubung', 'detail' => $e->getMessage()];
            }
        });
    }

    /** Server pasangan: dari lokal cek cloud, dari cloud cek lokal */
    public function cloud(bool $paksa = false): array
    {
        if ($paksa) {
            Cache::forget(self::CACHE.'cloud');
        }

        return Cache::remember(self::CACHE.'cloud', 60, function () {
            $diCloud = config('app.mode') === 'cloud';

            // Dari cloud, alamat LAN rental (192.168.x.x) memang tidak terjangkau: status server lokal dibaca dari
            // kapan server lokal terakhir menghubungi cloud lewat sinkron (tiap menit), bukan dengan ping
            if ($diCloud) {
                return $this->serverLokalDariSinkron();
            }

            $judul = 'Server cloud / VPS';
            $kunci = $diCloud ? 'server.url_lokal' : 'server.url_cloud';
            $url = StatusTvService::urlServer($this->pengaturan($kunci));

            if (! $url) {
                return [
                    'status' => 'info',
                    'judul' => $judul,
                    'nilai' => 'Belum diatur',
                    'detail' => 'Isi alamatnya di Pengaturan → Operasional → Alamat server.',
                ];
            }

            try {
                $mulai = microtime(true);
                $res = Http::timeout(4)->connectTimeout(3)->acceptJson()->get($url.'/api/ping');
                $ms = (int) round((microtime(true) - $mulai) * 1000);

                if ($res->ok() && $res->json('ok')) {
                    return ['status' => 'ok', 'judul' => $judul, 'nilai' => "Online ({$ms} ms)", 'detail' => $url];
                }

                return ['status' => 'peringatan', 'judul' => $judul, 'nilai' => 'Menjawab HTTP '.$res->status(), 'detail' => $url.' (belum versi terbaru?)'];
            } catch (Throwable) {
                return ['status' => 'mati', 'judul' => $judul, 'nilai' => 'Tidak bisa dihubungi', 'detail' => $url];
            }
        });
    }

    private function serverLokalDariSinkron(): array
    {
        $judul = 'Server lokal (rental)';
        $tenantId = app(\App\Support\Tenancy::class)->tenantId(); // super admin: semua rental
        $server = ServerSinkron::query()->where('is_active', true)->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->get();

        if ($server->isEmpty()) {
            return ['status' => 'info', 'judul' => $judul, 'nilai' => 'Belum terhubung',
                'detail' => 'Server lokal belum sinkron ke sini · Pengaturan → Sinkronisasi (token)'];
        }

        $terakhir = $server->max('terakhir_kontak');

        if (! $terakhir) {
            return ['status' => 'info', 'judul' => $judul, 'nilai' => 'Belum pernah kontak',
                'detail' => 'Token sudah dibuat; isi alamat cloud & token di server lokal lalu Simpan'];
        }

        $terakhir = Carbon::parse($terakhir);

        return [
            'status' => $terakhir->gt(now()->subMinutes(3)) ? 'ok' : ($terakhir->gt(now()->subMinutes(15)) ? 'peringatan' : 'mati'),
            'judul' => $judul,
            'nilai' => $terakhir->gt(now()->subMinutes(3)) ? 'Online' : 'Tidak ada kontak',
            'detail' => 'Sinkron terakhir '.$terakhir->diffForHumans()
                .' · bila server lokal mati, TV & kasir otomatis memakai server cloud ini',
        ];
    }

    /** Jumlah data yang belum pernah tersinkron (kolom synced_at kosong) */
    public function sync(): array
    {
        $sinkron = app(SinkronService::class);

        // Di cloud: tampilkan server lokal yang terhubung
        if ($sinkron->diCloud()) {
            $server = ServerSinkron::query()->where('is_active', true)->get();
            $aktif = $server->filter(fn ($s) => $s->terakhir_kontak?->gt(now()->subMinutes(10)))->count();

            return [
                'status' => $server->isEmpty() ? 'info' : ($aktif === $server->count() ? 'ok' : 'peringatan'),
                'judul' => 'Sinkronisasi (server lokal)',
                'nilai' => $server->isEmpty() ? 'Belum ada server' : "{$aktif} / {$server->count()} terhubung",
                'detail' => 'Server lokal rental mengirim data ke sini tiap menit · atur di Pengaturan → Sinkronisasi',
            ];
        }

        $status = $sinkron->status();
        $antrean = $sinkron->jumlahAntrean();

        if (! $sinkron->siap()) {
            return [
                'status' => 'info',
                'judul' => 'Sinkronisasi cloud',
                'nilai' => 'Belum diaktifkan',
                'detail' => number_format($antrean, 0, ',', '.').' perubahan menunggu · atur di Pengaturan → Sinkronisasi',
            ];
        }

        $terakhir = $status['terakhir_ok'] ? Carbon::parse($status['terakhir_ok']) : null;
        $lambat = ! $terakhir || $terakhir->lt(now()->subMinutes(10));

        return [
            'status' => $status['error'] ? 'mati' : ($lambat ? 'peringatan' : 'ok'),
            'judul' => 'Sinkronisasi cloud',
            'nilai' => $status['error'] ? 'Gagal' : ($terakhir ? 'Terakhir '.$terakhir->diffForHumans() : 'Belum pernah'),
            'detail' => ($status['error'] ? $status['error'].' · ' : '')
                .number_format($antrean, 0, ',', '.').' perubahan menunggu dikirim'
                .($lambat && ! $status['error'] ? ' · sinkron otomatis butuh scheduler berjalan' : ''),
        ];
    }

    public function antrean(): array
    {
        $tunggu = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $gagal = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $tertua = Schema::hasTable('jobs') ? DB::table('jobs')->min('created_at') : null;
        $macet = $tertua && now()->timestamp - (int) $tertua > 120;

        return [
            'status' => $macet ? 'mati' : ($gagal > 0 ? 'peringatan' : 'ok'),
            'judul' => 'Antrean (queue worker)',
            'nilai' => $macet ? 'Tidak berjalan' : ($tunggu > 0 ? "{$tunggu} menunggu" : 'Lancar'),
            'detail' => ($gagal > 0 ? "{$gagal} tugas gagal · " : '').'Mengirim sinyal ke TV, notifikasi Telegram/WA'
                .($macet ? ' · jalankan: php artisan queue:work' : ''),
        ];
    }

    public function realtime(): array
    {
        return Cache::remember(self::CACHE.'realtime', 30, function () {
            $host = config('reverb.servers.reverb.hostname') ?: config('broadcasting.connections.reverb.options.host') ?: '127.0.0.1';
            $host = in_array($host, ['0.0.0.0', 'localhost'], true) ? '127.0.0.1' : $host;
            $port = (int) (config('reverb.servers.reverb.port') ?: 8080);

            $sock = @fsockopen($host, $port, $errno, $errstr, 1.5);

            if ($sock) {
                fclose($sock);

                return ['status' => 'ok', 'judul' => 'Realtime (Reverb)', 'nilai' => 'Berjalan', 'detail' => "{$host}:{$port} · TV menerima perintah seketika"];
            }

            return [
                'status' => 'peringatan',
                'judul' => 'Realtime (Reverb)',
                'nilai' => 'Tidak berjalan',
                'detail' => "{$host}:{$port} · TV tetap jalan lewat polling, perintah sedikit terlambat · jalankan: php artisan reverb:start",
            ];
        });
    }

    public function backup(): array
    {
        $terakhir = app(BackupService::class)->terakhir();

        if (! $terakhir) {
            return ['status' => 'mati', 'judul' => 'Backup', 'nilai' => 'Belum pernah', 'detail' => 'Buat backup pertama di Platform → Backup'];
        }

        $lama = $terakhir->lt(now()->subHours(36));

        return [
            'status' => $lama ? 'peringatan' : 'ok',
            'judul' => 'Backup',
            'nilai' => $terakhir->diffForHumans(),
            'detail' => $terakhir->format('d/m/Y H:i').' · '.app(BackupService::class)->daftar()->count().' file'
                .($lama ? ' · backup otomatis tidak berjalan (scheduler mati?)' : ''),
        ];
    }

    /** Nilai pengaturan tenant aktif (cabang aktif dulu, lalu tingkat tenant) */
    private function pengaturan(string $kunci): mixed
    {
        return Pengaturan::ambil($kunci);
    }

    public function tv(): array
    {
        $semua = PerangkatTv::query()->aktif()->get(['id', 'terakhir_online']);
        $online = $semua->filter(fn (PerangkatTv $t) => $t->isOnline())->count();
        $total = $semua->count();

        return [
            'status' => $total === 0 ? 'info' : ($online === $total ? 'ok' : ($online === 0 ? 'mati' : 'peringatan')),
            'judul' => 'TV Agent',
            'nilai' => "{$online} / {$total} online",
            'detail' => $total === 0 ? 'Belum ada TV terpasang' : ($total - $online > 0 ? ($total - $online).' TV tidak terhubung' : 'Semua TV terhubung'),
        ];
    }
}
