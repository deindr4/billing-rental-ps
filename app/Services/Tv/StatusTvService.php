<?php

namespace App\Services\Tv;

use App\Models\Cabang;
use App\Models\PembayaranOnline;
use App\Models\Pengaturan;
use App\Models\PerangkatTv;
use App\Models\Sesi;
use App\Models\Transaksi;
use App\Models\TransaksiDiskon;
use App\Models\TransaksiItem;
use App\Models\Unit;
use App\Services\Billing\BillingService;
use App\Services\Gateway\BayarMandiriService;
use App\Services\Gateway\PengaturanGateway;
use App\Services\Publik\QrisService;
use App\Support\PengaturanPc;
use App\Support\RunningTextTv;
use App\Support\Tema;
use Illuminate\Support\Facades\Storage;

/**
 * Status lengkap yang ditampilkan TV. Harus dipanggil dengan tenancy perangkat aktif.
 *
 * Field `layar` memberi tahu TV apa yang harus ditampilkan:
 *   belum_ada_unit | servis | bypass | kunci | main | jeda | habis | menunggu_bayar
 * TV cukup mengikuti field ini; timer dihitung lokal dari waktu server supaya tetap jalan saat offline.
 */
final class StatusTvService
{
    public const POLL_DETIK = 15;

    public function untuk(PerangkatTv $perangkat): array
    {
        $unit = $perangkat->unit_id
            ? Unit::withoutGlobalScopes()->with(['tipeKonsol:id,kode,nama', 'kategori:id,nama'])->find($perangkat->unit_id)
            : null;
        $cabang = Cabang::with('tenant')->find($perangkat->cabang_id);

        // QRIS bayar mandiri yang sedang menunggu: cek ke gateway dulu, supaya TV langsung terbuka begitu lunas
        $tagihan = $unit ? PembayaranOnline::withoutGlobalScopes()->where('unit_id', $unit->id)
            ->whereIn('status', ['menunggu', 'dibayar'])->latest()->first() : null;

        if ($tagihan) {
            $tagihan = app(BayarMandiriService::class)->periksa($tagihan);
            $unit->refresh();
        }

        $sesi = $unit ? $this->sesiUnit($unit) : null;
        $sekarang = now();
        $zona = $cabang?->zona_waktu ?: config('app.timezone');

        return [
            'server_time_ms' => $sekarang->getTimestampMs(),
            'poll_detik' => self::POLL_DETIK,
            'layar' => $this->layar($perangkat, $unit, $sesi),
            'perangkat' => [
                'id' => $perangkat->id,
                'jenis' => $perangkat->jenis ?? PerangkatTv::JENIS_TV,
                'nama' => $perangkat->namaTampil(),
                'bypass_sampai_ms' => $perangkat->sedangBypass() ? $perangkat->bypass_sampai->getTimestampMs() : null,
            ],
            'unit' => $unit ? [
                'id' => $unit->id,
                'kode' => $unit->kode,
                'nama' => $unit->nama,
                'status' => $unit->status,
                'konsol' => $unit->tipeKonsol?->nama,
                'konsol_kode' => $unit->tipeKonsol?->kode,
                'kategori' => $unit->kategori?->nama,
                'lokasi' => $unit->lokasi,
                'tarif_per_jam' => rescue(fn () => app(BillingService::class)->tarifPerJam($unit), null, false),
            ] : null,
            'sesi' => $sesi ? $this->dataSesi($sesi, $zona) : null,
            'pengaturan' => $this->pengaturan($perangkat, $unit),
            // Agen kiosk PC: akhir sesi, proteksi, izin Task Manager & daftar aplikasi kiosk (null untuk TV)
            'pc' => $perangkat->isPc() ? PengaturanPc::ambil($perangkat->cabang_id) : null,
            'tema' => [
                'nama_rental' => $cabang?->tenant?->nama ?? config('app.name'),
                'cabang' => $cabang?->nama,
                'logo_url' => $this->urlPublik(Pengaturan::ambil('tema.logo')),
                // Wallpaper layar kunci: khusus unit, atau default dari Admin → Tampilan
                'wallpaper_url' => $this->urlPublik($unit?->wallpaper ?: Pengaturan::ambil('tv.wallpaper')),
                'mode' => Tema::mode(),
                'aksen' => Tema::aksen(),
                'zona_waktu' => $zona,
                'zona_label' => self::ZONA_LABEL[$zona] ?? '',
            ],
            'pengumuman' => trim((string) Pengaturan::ambil('tv.pengumuman', '', $perangkat->cabang_id)) ?: null,
            // Running text promo dari kasir (APK >= 0.6.0), tampil di atas layar kunci & game; null = mati / habis
            'running_text' => RunningTextTv::untukApi($perangkat->cabang_id),
            // Alamat server untuk failover TV: lokal (utama, LAN) & cloud (cadangan). Diatur di Admin → Operasional.
            'server' => [
                'lokal' => self::urlServer(Pengaturan::ambil('server.url_lokal', null, $perangkat->cabang_id)),
                'cloud' => self::urlServer(Pengaturan::ambil('server.url_cloud', null, $perangkat->cabang_id)),
                'asal' => config('app.mode') === 'cloud' ? 'cloud' : 'lokal', // server yang menjawab request ini
            ],
            // Bayar mandiri: QR "scan untuk main / isi ulang" + QRIS nominal yang sedang menunggu dibayar
            'bayar_mandiri' => $unit ? $this->bayarMandiri($unit, $tagihan) : null,
            // Perintah remote yang belum kedaluwarsa (cadangan jika websocket putus)
            'perintah' => TvRemoteService::antrean($perangkat->id),
            'realtime' => $this->realtime($perangkat),
        ];
    }

    /** Blok bayar mandiri untuk layar kunci / layar habis (null = fitur tidak tersedia saat ini) */
    private function bayarMandiri(Unit $unit, ?PembayaranOnline $tagihan): ?array
    {
        $layanan = app(BayarMandiriService::class);
        $k = $layanan->konteks($unit);

        if (! $k['bisa']) {
            return null;
        }

        $aktif = $tagihan && $tagihan->masihBisaDibayar() ? $tagihan : null;

        return [
            'jenis' => $k['jenis'],                           // mulai | isi_ulang
            // Halaman HP untuk mengetik nominal (alamat server yang dipakai TV, bisa dibuka HP di Wi-Fi rental)
            'url' => request()->getSchemeAndHttpHost().'/main/'.$layanan->token($unit),
            'tarif_per_jam' => $layanan->tarif($unit),
            'minimal_menit' => app(PengaturanGateway::class)->minimalMenit($unit->cabang_id),
            'tagihan' => $aktif ? [
                'id' => $aktif->id,
                'qris' => $aktif->qr_string,
                // qris = string QRIS (scan pakai app bank/e-wallet) | tautan = halaman bayar gateway (scan pakai kamera HP)
                'tipe' => $aktif->urlBayar() ? 'tautan' : 'qris',
                'nominal' => $aktif->nominal,
                'menit' => $aktif->menit,
                'label' => $layanan->labelMenit($aktif->menit),
                'kedaluwarsa_ms' => $aktif->kedaluwarsa_pada->getTimestampMs(),
            ] : null,
        ];
    }

    /** "192.168.1.10" -> "http://192.168.1.10", tanpa garis miring di akhir */
    public static function urlServer(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'http://'.$url;
        }

        return rtrim($url, '/');
    }

    public const ZONA_LABEL = ['Asia/Jakarta' => 'WIB', 'Asia/Makassar' => 'WITA', 'Asia/Jayapura' => 'WIT'];

    /** Warna angka timer TV saat waktu masih banyak. Merah tidak tersedia: khusus tanda waktu hampir habis. */
    public const WARNA_TIMER = [
        'putih' => '#E6EDF5',
        'hijau' => '#4ADE80',
        'biru' => '#38BDF8',
        'tosca' => '#2DD4BF',
        'kuning' => '#FACC15',
    ];

    /** URL file publik memakai host yang dipakai TV (APP_URL bisa "localhost" yang tidak terjangkau dari TV) */
    private function urlPublik(?string $path): ?string
    {
        return $path && Storage::disk('public')->exists($path)
            ? request()->getSchemeAndHttpHost().'/storage/'.ltrim($path, '/')
            : null;
    }

    /** Sesi aktif, atau sesi selesai yang belum dibayar */
    private function sesiUnit(Unit $unit): ?Sesi
    {
        return Sesi::withoutGlobalScopes()
            ->with(['transaksi', 'paketHarga:id,nama'])
            ->where('unit_id', $unit->id)
            ->where(function ($q) {
                $q->aktif()->orWhere(function ($q) {
                    $q->where('status', Sesi::STATUS_SELESAI)
                        ->whereHas('transaksi', fn ($t) => $t->withoutGlobalScopes()->where('status', Transaksi::STATUS_BELUM_BAYAR));
                });
            })
            ->latest('mulai_pada')
            ->first();
    }

    private function layar(PerangkatTv $perangkat, ?Unit $unit, ?Sesi $sesi): string
    {
        if (! $unit) {
            return 'belum_ada_unit';
        }

        if ($unit->status === Unit::STATUS_SERVIS) {
            return 'servis';
        }

        if ($sesi?->status === Sesi::STATUS_DIJEDA) {
            return 'jeda';
        }

        if ($sesi?->status === Sesi::STATUS_BERJALAN) {
            return $sesi->isPaket() && $sesi->sisaDetik() === 0 ? 'habis' : 'main';
        }

        // Bypass membuka TV hanya saat tidak ada sesi (misal tes/servis ringan)
        if ($perangkat->sedangBypass()) {
            return 'bypass';
        }

        if ($unit->status === Unit::STATUS_MENUNGGU_BAYAR) {
            return 'menunggu_bayar';
        }

        return 'kunci';
    }

    private function dataSesi(Sesi $sesi, string $zona): array
    {
        $transaksi = $sesi->transaksi;
        $tagihan = (int) ($transaksi?->total ?? 0);
        $estimasiSewa = null;

        // Open billing berjalan: perkiraan biaya sewa sampai detik ini (bonus waktu tidak ditagih)
        $estimasiSewa = app(BillingService::class)->estimasiSewaOpen($sesi);

        return [
            'id' => $sesi->id,
            'mode' => $sesi->mode,
            'status' => $sesi->status,
            'paket' => $sesi->paketHarga?->nama,
            'mulai_ms' => $sesi->mulai_pada->getTimestampMs(),
            'berakhir_ms' => $sesi->berakhir_pada?->getTimestampMs(),
            'dijeda_ms' => $sesi->dijeda_pada?->getTimestampMs(),
            'selesai_ms' => $sesi->selesai_pada?->getTimestampMs(),
            // TV menghitung "sudah main" = sekarang - mulai - nilai ini; bonus waktu ikut dikurangkan (tidak ditagih)
            'total_jeda_detik' => (int) $sesi->total_jeda_detik + (int) $sesi->bonus_detik,
            'sisa_detik' => $sesi->sisaDetik(),
            'durasi_detik' => $sesi->durasiBerjalanDetik(),
            'tarif_per_jam' => $sesi->tarif_per_jam,
            'versi_tagihan' => (int) $sesi->versi_tagihan,
            'tagihan' => [
                'nomor' => $transaksi?->nomor,
                'pelanggan' => $transaksi?->pelanggan_nama,
                // total item yang sudah tercatat (paket, tambah waktu, F&B)
                'total' => $tagihan,
                // open billing: perkiraan sewa berjalan, belum termasuk di total
                'estimasi_sewa' => $estimasiSewa,
                'lunas' => (bool) $transaksi?->isLunas(),
                'subtotal' => (int) ($transaksi?->subtotal ?? 0),
                'total_diskon' => (int) ($transaksi?->total_diskon ?? 0),
                'sisa' => $transaksi && ! $transaksi->isDibatalkan() ? $transaksi->sisaTagihan() : 0,
                'items' => $transaksi ? $this->itemTagihan($transaksi, $zona) : [],
                'diskon' => $transaksi
                    ? TransaksiDiskon::withoutGlobalScopes()->where('transaksi_id', $transaksi->id)->get(['nama', 'nilai'])
                        ->map(fn ($d) => ['nama' => $d->nama, 'nilai' => (int) $d->nilai])->all()
                    : [],
                // QRIS dinamis untuk sisa tagihan (layar "waktu habis" menampilkan QR bayar)
                'qris' => $transaksi && ! $transaksi->isDibatalkan() && $transaksi->sisaTagihan() > 0
                    ? app(QrisService::class)->untukNominal($transaksi->cabang_id, $transaksi->sisaTagihan())
                    : null,
            ],
        ];
    }

    /** Rincian item untuk layar tagihan TV (desain Stitch 11) */
    private function itemTagihan(Transaksi $transaksi, string $zona): array
    {
        $label = ['sewa' => 'Sewa', 'tambah_waktu' => 'Tambah waktu', 'produk' => 'F&B', 'aksesori' => 'Aksesori', 'lainnya' => 'Lainnya'];

        return TransaksiItem::withoutGlobalScopes()
            ->where('transaksi_id', $transaksi->id)
            // F&B yang dibatalkan penuh (salah order, qty 0) tidak perlu dilihat pelanggan
            ->where(fn ($q) => $q->where('jenis', '!=', TransaksiItem::JENIS_PRODUK)->orWhere('qty', '>', 0))
            ->orderBy('created_at')
            ->get()
            ->map(fn (TransaksiItem $i) => [
                'nama' => $i->nama,
                'jenis' => $i->jenis,
                'label' => $label[$i->jenis] ?? $i->jenis,
                'qty' => (int) $i->qty,
                'harga_satuan' => (int) $i->harga_satuan,
                'subtotal' => (int) $i->subtotal,
                // Jam masuk/keluar hanya untuk sewa; catatan lain bersifat internal
                'keterangan' => $i->jenis === TransaksiItem::JENIS_SEWA ? $i->catatan : null,
            ])
            ->all();
    }

    private function pengaturan(PerangkatTv $perangkat, ?Unit $unit): array
    {
        $cabangId = $perangkat->cabang_id;

        return [
            'posisi_timer' => $unit?->posisi_timer ?? Pengaturan::ambil('tv.posisi_timer', 'kanan_atas', $cabangId),
            'peringatan_menit' => (int) Pengaturan::ambil('tv.peringatan_menit', 5, $cabangId),
            'transparansi_lock' => (int) Pengaturan::ambil('tv.transparansi_lock', 85, $cabangId),
            // Kepekatan timer melayang (%); TV memakai 100% saat waktu hampir habis / banner peringatan
            'opasitas_timer' => max(30, min(100, (int) Pengaturan::ambil('tv.opasitas_timer', 90, $cabangId))),
            // Ukuran timer melayang di atas game: kecil | sedang | besar (APK >= 0.6.3)
            'ukuran_timer' => in_array($u = Pengaturan::ambil('tv.ukuran_timer', 'sedang', $cabangId), ['kecil', 'sedang', 'besar'], true) ? $u : 'sedang',
            // Warna angka timer saat waktu masih banyak (hampir habis tetap merah) (APK >= 0.6.4)
            'warna_timer' => self::WARNA_TIMER[Pengaturan::ambil('tv.warna_timer', 'putih', $cabangId)] ?? self::WARNA_TIMER['putih'],
            // Baris kecil di bawah timer: versi APK + respon ke server lokal/cloud (APK >= 0.6.5)
            'info_teknis' => (bool) Pengaturan::ambil('tv.info_teknis', true, $cabangId),
            // Saat main hanya Volume, Home & OK yang diteruskan ke PS; butuh izin Aksesibilitas di TV (APK >= 0.6.6)
            'kunci_remote' => (bool) Pengaturan::ambil('tv.kunci_remote', true, $cabangId),
            'durasi_bypass_menit' => $this->durasiBypass($perangkat, $unit),
            'bypass_maks_menit' => $this->bypassMaks($cabangId),
            'bypass_pilihan' => $this->pilihanBypass($perangkat, $unit),
            // Input HDMI tempat PS tersambung (dipilih saat setup di TV atau dari admin)
            'input_hdmi' => $perangkat->input_hdmi,
            'input_hdmi_label' => $perangkat->input_hdmi_label,
            // Aplikasi TV yang boleh dibuka saat bypass (selain PS/HDMI)
            'aplikasi' => self::aplikasiDiizinkan($cabangId),
            // Bunyi peringatan sisa waktu & waktu habis
            'suara_aktif' => (bool) Pengaturan::ambil('tv.suara_aktif', true, $cabangId),
        ];
    }

    public const APLIKASI_DEFAULT = [['nama' => 'YouTube', 'paket' => 'com.google.android.youtube.tv']];

    /** @return array<int, array{nama:string, paket:string}> */
    public static function aplikasiDiizinkan(?string $cabangId): array
    {
        $daftar = Pengaturan::ambil('tv.aplikasi', self::APLIKASI_DEFAULT, $cabangId);

        return array_values(array_filter((array) $daftar, fn ($a) => ! empty($a['nama']) && ! empty($a['paket'])));
    }

    public function bypassMaks(?string $cabangId): int
    {
        return max(5, (int) Pengaturan::ambil('tv.bypass_maks_menit', 120, $cabangId));
    }

    /** Pilihan durasi di layar bypass: 15/30/60/120 (tidak melebihi batas) + durasi default */
    public function pilihanBypass(PerangkatTv $perangkat, ?Unit $unit = null): array
    {
        $maks = $this->bypassMaks($perangkat->cabang_id);

        return collect([15, 30, 60, 120, $this->durasiBypass($perangkat, $unit)])
            ->filter(fn ($m) => $m >= 5 && $m <= $maks)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function durasiBypass(PerangkatTv $perangkat, ?Unit $unit = null): int
    {
        $unit ??= $perangkat->unit_id ? Unit::withoutGlobalScopes()->find($perangkat->unit_id) : null;

        return (int) ($unit?->durasi_bypass_menit ?: Pengaturan::ambil('tv.durasi_bypass_menit', 15, $perangkat->cabang_id));
    }

    /** Koneksi Reverb untuk TV. Host diambil dari alamat yang dipakai TV menghubungi server (IP LAN). */
    private function realtime(PerangkatTv $perangkat): ?array
    {
        // Hosting tanpa Reverb (BROADCAST_CONNECTION bukan reverb): TV cukup polling, tidak mencoba websocket terus
        if (config('broadcasting.default') !== 'reverb') {
            return null;
        }

        $koneksi = config('broadcasting.connections.reverb');

        return [
            'key' => $koneksi['key'] ?? null,
            'host' => config('billing.tv.ws_host') ?: request()->getHost(),
            'port' => (int) (config('billing.tv.ws_port') ?: ($koneksi['options']['port'] ?? 8080)),
            'scheme' => config('billing.tv.ws_scheme') ?: ($koneksi['options']['scheme'] ?? 'http'),
            'channel' => 'private-'.$perangkat->channel(),
            'event' => '.segarkan',
            'auth_url' => url('/api/tv/broadcasting/auth'),
        ];
    }
}
