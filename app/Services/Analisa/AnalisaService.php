<?php

namespace App\Services\Analisa;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\PaketHarga;
use App\Models\Pengeluaran;
use App\Models\ProdukStok;
use App\Models\Sesi;
use App\Models\Shift;
use App\Models\StokMutasi;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\Aset\AsetService;
use App\Services\LaporanService;
use App\Services\Publik\BookingService;
use App\Support\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Analisa pintar berbasis aturan (tanpa AI, jalan offline): audit kecurangan kasir, keuangan, operasional, stok.
 * Setiap bagian menghasilkan angka + "temuan" (tingkat tinggi / sedang / info, beserta saran).
 * Mengikuti scope tenant & cabang aktif. Ambang batas di konstanta di bawah supaya mudah disetel.
 */
final class AnalisaService
{
    /** Urutan tampil temuan */
    public const TINGKAT = ['tinggi' => 0, 'sedang' => 1, 'info' => 2];

    /** Skor risiko kasir: >= TINGGI merah, >= SEDANG kuning */
    public const SKOR_TINGGI = 50;

    public const SKOR_SEDANG = 25;

    /** Aksi log aktivitas yang dihitung per kasir */
    private const AKSI_KASIR = ['bypass_tv', 'kode_darurat', 'batal_fnb', 'batal_tambah_waktu', 'pin_gagal', 'waktu_gratis'];

    public function __construct(private LaporanService $laporan) {}

    /** @return array{temuan: Collection, kasir: array, keuangan: array, operasional: array, stok: array} */
    public function semua(CarbonInterface $dari, CarbonInterface $sampai): array
    {
        $kasir = $this->auditKasir($dari, $sampai);
        $keuangan = $this->keuangan($dari, $sampai);
        $operasional = $this->operasional($dari, $sampai);
        $stok = $this->stok($dari, $sampai);

        $temuan = collect([...$kasir['temuan'], ...$keuangan['temuan'], ...$operasional['temuan'], ...$stok['temuan']])
            ->sortBy(fn ($t) => self::TINGKAT[$t['tingkat']])
            ->values();

        return compact('temuan', 'kasir', 'keuangan', 'operasional', 'stok');
    }

    private static function temuan(string $tingkat, string $bagian, string $judul, string $detail, string $saran): array
    {
        return compact('tingkat', 'bagian', 'judul', 'detail', 'saran');
    }

    private static function rp(int $n): string
    {
        return 'Rp '.number_format($n, 0, ',', '.');
    }

    /* ======================= Audit kecurangan kasir ======================= */

    public function auditKasir(CarbonInterface $dari, CarbonInterface $sampai): array
    {
        $trx = Transaksi::query()
            ->where('is_latihan', false)
            ->where('jenis', '!=', Transaksi::JENIS_TOP_UP)
            ->whereBetween('created_at', [$dari, $sampai])
            ->selectRaw('user_id, COUNT(*) as jumlah')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'lunas' THEN total ELSE 0 END), 0) as omzet")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'lunas' THEN total_diskon ELSE 0 END), 0) as diskon")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'dibatalkan' THEN 1 ELSE 0 END), 0) as batal")
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'dibatalkan' THEN total ELSE 0 END), 0) as nilai_batal")
            ->groupBy('user_id')->get()->keyBy('user_id');

        $shift = Shift::query()
            ->where('status', Shift::STATUS_TUTUP)
            ->whereBetween('ditutup_pada', [$dari, $sampai])
            ->selectRaw('user_id, COUNT(*) as shift')
            ->selectRaw('COALESCE(SUM(CASE WHEN selisih < 0 THEN 1 ELSE 0 END), 0) as kurang')
            ->selectRaw('COALESCE(SUM(CASE WHEN selisih < 0 THEN -selisih ELSE 0 END), 0) as nilai_kurang')
            ->selectRaw('COALESCE(SUM(CASE WHEN selisih > 0 THEN selisih ELSE 0 END), 0) as nilai_lebih')
            ->groupBy('user_id')->get()->keyBy('user_id');

        $sesi = Sesi::query()
            ->whereBetween('mulai_pada', [$dari, $sampai])
            ->selectRaw('user_id, COUNT(*) as sesi')
            ->selectRaw('COALESCE(SUM(bonus_detik), 0) as bonus_detik')
            ->selectRaw("COALESCE(SUM(CASE WHEN status = 'dibatalkan' THEN 1 ELSE 0 END), 0) as sesi_batal")
            ->groupBy('user_id')->get()->keyBy('user_id');

        $cabangId = app(Tenancy::class)->cabangId();
        $aksi = AuditLog::query()
            ->whereIn('aksi', self::AKSI_KASIR)
            ->whereNotNull('user_id')
            ->whereBetween('created_at', [$dari, $sampai])
            ->when($cabangId, fn ($q) => $q->where('cabang_id', $cabangId))
            ->selectRaw('user_id, aksi, COUNT(*) as n')
            ->groupBy('user_id', 'aksi')->get()
            ->groupBy('user_id')
            ->map(fn ($baris) => $baris->pluck('n', 'aksi')->map(fn ($n) => (int) $n));

        $ids = collect([$trx->keys(), $shift->keys(), $sesi->keys(), $aksi->keys()])->flatten()->unique()->filter()->values();
        $nama = User::query()->whereIn('id', $ids)->pluck('name', 'id');

        $baris = $ids->map(function (string $id) use ($trx, $shift, $sesi, $aksi, $nama) {
            $t = $trx[$id] ?? null;
            $s = $shift[$id] ?? null;
            $se = $sesi[$id] ?? null;
            $a = $aksi[$id] ?? collect();
            $jumlah = (int) ($t->jumlah ?? 0);
            $omzet = (int) ($t->omzet ?? 0);
            $diskon = (int) ($t->diskon ?? 0);

            return [
                'user_id' => $id,
                'nama' => $nama[$id] ?? '(pengguna dihapus)',
                'transaksi' => $jumlah,
                'omzet' => $omzet,
                'batal' => (int) ($t->batal ?? 0),
                'nilai_batal' => (int) ($t->nilai_batal ?? 0),
                'persen_batal' => $jumlah > 0 ? round(($t->batal ?? 0) * 100 / $jumlah, 1) : 0.0,
                'diskon' => $diskon,
                'persen_diskon' => ($omzet + $diskon) > 0 ? round($diskon * 100 / ($omzet + $diskon), 1) : 0.0,
                'shift' => (int) ($s->shift ?? 0),
                'kas_kurang_kali' => (int) ($s->kurang ?? 0),
                'kas_kurang' => (int) ($s->nilai_kurang ?? 0),
                'kas_lebih' => (int) ($s->nilai_lebih ?? 0),
                'bonus_menit' => intdiv((int) ($se->bonus_detik ?? 0), 60),
                'sesi_batal' => (int) ($se->sesi_batal ?? 0),
                'bypass' => (int) ($a['bypass_tv'] ?? 0) + (int) ($a['kode_darurat'] ?? 0),
                'batal_item' => (int) ($a['batal_fnb'] ?? 0) + (int) ($a['batal_tambah_waktu'] ?? 0),
                'pin_gagal' => (int) ($a['pin_gagal'] ?? 0),
            ];
        });

        // Pembanding: rata-rata semua kasir di periode ini
        $rataBatal = (float) $baris->avg('persen_batal');
        $rataDiskon = (float) $baris->avg('persen_diskon');
        $rataBonus = (float) $baris->avg('bonus_menit');

        $baris = $baris->map(fn ($k) => $k + $this->skorKasir($k, $rataBatal, $rataDiskon, $rataBonus))
            ->sortByDesc('skor')->values();

        $temuan = $baris->filter(fn ($k) => $k['skor'] >= self::SKOR_SEDANG)->map(fn ($k) => self::temuan(
            $k['skor'] >= self::SKOR_TINGGI ? 'tinggi' : 'sedang',
            'Kasir',
            "{$k['nama']}: skor risiko {$k['skor']}",
            implode(' · ', $k['alasan']),
            'Cocokkan dengan Log aktivitas & rekaman CCTV di jam kejadian, lalu minta penjelasan. Skor tinggi belum tentu curang.'
        ))->all();

        $totalKurang = (int) $baris->sum('kas_kurang');
        if ($totalKurang > 0) {
            $temuan[] = self::temuan($totalKurang >= 100_000 ? 'sedang' : 'info', 'Kasir', 'Kas laci kurang '.self::rp($totalKurang),
                'Jumlah selisih kurang saat tutup kas di periode ini ('.$baris->sum('kas_kurang_kali').' shift).',
                'Biasakan hitung pecahan saat tutup kas; selisih berulang pada kasir yang sama perlu ditelusuri.');
        }

        return [
            'baris' => $baris,
            'rata_batal' => round($rataBatal, 1),
            'rata_diskon' => round($rataDiskon, 1),
            'temuan' => $temuan,
        ];
    }

    /** @return array{skor:int, alasan:array<int,string>} */
    private function skorKasir(array $k, float $rataBatal, float $rataDiskon, float $rataBonus): array
    {
        $skor = 0;
        $alasan = [];

        if ($k['batal'] >= 3 && $k['persen_batal'] >= max(5, 2 * $rataBatal)) {
            $skor += 25;
            $alasan[] = "transaksi dibatalkan {$k['batal']}× ({$k['persen_batal']}%, rata-rata ".round($rataBatal, 1).'%)';
        }

        if ($k['kas_kurang_kali'] >= 2 || $k['kas_kurang'] >= 50_000) {
            $skor += 25;
            $alasan[] = "kas kurang {$k['kas_kurang_kali']}× shift (".self::rp($k['kas_kurang']).')';
        }

        if ($k['bonus_menit'] >= 60 && $k['bonus_menit'] >= 2 * $rataBonus) {
            $skor += 15;
            $alasan[] = "bonus waktu gratis {$k['bonus_menit']} menit";
        }

        if ($k['bypass'] >= 3) {
            $skor += 15;
            $alasan[] = "bypass / kode darurat TV {$k['bypass']}×";
        }

        if ($k['batal_item'] >= 5) {
            $skor += 10;
            $alasan[] = "batal F&B / tambah waktu {$k['batal_item']}×";
        }

        if ($k['sesi_batal'] >= 3) {
            $skor += 10;
            $alasan[] = "sesi dibatalkan {$k['sesi_batal']}×";
        }

        if ($k['diskon'] >= 50_000 && $k['persen_diskon'] >= max(10, 2 * $rataDiskon)) {
            $skor += 10;
            $alasan[] = 'diskon '.self::rp($k['diskon'])." ({$k['persen_diskon']}% dari penjualan)";
        }

        if ($k['pin_gagal'] >= 3) {
            $skor += 10;
            $alasan[] = "PIN salah {$k['pin_gagal']}×";
        }

        return ['skor' => min(100, $skor), 'alasan' => $alasan];
    }

    /* ============================== Keuangan ============================== */

    public function keuangan(CarbonInterface $dari, CarbonInterface $sampai): array
    {
        $hari = (int) $dari->diffInDays($sampai) + 1;
        $r = $this->laporan->ringkasan($dari, $sampai);
        $lalu = $this->laporan->ringkasan($dari->copy()->subDays($hari), $dari->copy()->subSecond());
        $temuan = [];

        $omzet = $r['omzet_bersih'];
        $perubahan = $lalu['omzet_bersih'] > 0 ? round(($omzet - $lalu['omzet_bersih']) * 100 / $lalu['omzet_bersih'], 1) : null;
        $marginKotor = $omzet > 0 ? round($r['laba_kotor'] * 100 / $omzet, 1) : 0.0;
        $marginBersih = $omzet > 0 ? round($r['laba_bersih'] * 100 / $omzet, 1) : 0.0;
        $rasioBeban = $omzet > 0 ? round($r['beban'] * 100 / $omzet, 1) : 0.0;
        $marginFnb = $r['pendapatan_fnb'] > 0 ? round(($r['pendapatan_fnb'] - $r['hpp']) * 100 / $r['pendapatan_fnb'], 1) : null;

        if ($omzet > 0 && $r['laba_bersih'] < 0) {
            $temuan[] = self::temuan('tinggi', 'Keuangan', 'Rugi '.self::rp(-$r['laba_bersih']).' di periode ini',
                "Pengeluaran operasional ".self::rp($r['beban'])." melebihi laba kotor ".self::rp($r['laba_kotor']).'.',
                'Tinjau pengeluaran terbesar di bawah; periode pendek (harian) wajar rugi bila ada belanja besar.');
        }

        if ($perubahan !== null && $perubahan <= -20) {
            $temuan[] = self::temuan('sedang', 'Keuangan', "Omzet turun {$perubahan}%",
                'Dibanding '.$hari.' hari sebelumnya ('.self::rp($lalu['omzet_bersih']).' → '.self::rp($omzet).').',
                'Cek jam/hari yang turun di bagian Operasional; pertimbangkan promo jam sepi atau event turnamen.');
        } elseif ($perubahan !== null && $perubahan >= 20) {
            $temuan[] = self::temuan('info', 'Keuangan', "Omzet naik {$perubahan}%", 'Dibanding '.$hari.' hari sebelumnya.', 'Pertahankan; catat promo/event yang berjalan.');
        }

        if ($marginFnb !== null && $marginFnb < 20) {
            $temuan[] = self::temuan('sedang', 'Keuangan', "Margin F&B tipis ({$marginFnb}%)",
                'Penjualan F&B '.self::rp($r['pendapatan_fnb']).', HPP '.self::rp($r['hpp']).'.',
                'Cek produk margin minus di bagian Stok; naikkan harga jual atau cari pemasok lebih murah. Pastikan harga pokok stok masuk benar (Stok → Riwayat → Koreksi).');
        }

        // Pengeluaran per kategori dibanding rata-rata 3 periode sebelumnya
        $sekarang = $this->pengeluaranKategori($dari, $sampai);
        $sebelum = collect(range(1, 3))->map(fn ($i) => $this->pengeluaranKategori(
            $dari->copy()->subDays($hari * $i), $dari->copy()->subDays($hari * ($i - 1))->subSecond()
        ));
        $lonjakan = $sekarang->map(function (int $total, string $kategori) use ($sebelum) {
            $rata = (int) round($sebelum->avg(fn ($p) => $p[$kategori] ?? 0));

            return ['kategori' => Pengeluaran::KATEGORI[$kategori] ?? $kategori, 'total' => $total, 'rata' => $rata];
        })->filter(fn ($p) => $p['total'] >= 100_000 && $p['rata'] > 0 && $p['total'] >= 1.5 * $p['rata'])->values();

        foreach ($lonjakan as $p) {
            $temuan[] = self::temuan('sedang', 'Keuangan', "Pengeluaran {$p['kategori']} melonjak",
                self::rp($p['total']).' vs rata-rata '.self::rp($p['rata']).' per periode yang sama sebelumnya.',
                'Periksa nota & siapa yang mencatat di menu Pengeluaran.');
        }

        // Titik impas harian: beban per hari ÷ rasio laba kotor
        $bebanHarian = intdiv($r['beban'], max(1, $hari));
        $omzetHarian = intdiv($omzet, max(1, $hari));
        $bepHarian = $marginKotor > 0 ? (int) round($bebanHarian * 100 / $marginKotor) : null;

        if ($bepHarian && $omzetHarian < $bepHarian && $hari >= 7) {
            $temuan[] = self::temuan('tinggi', 'Keuangan', 'Omzet harian di bawah titik impas',
                'Rata-rata '.self::rp($omzetHarian).'/hari, titik impas '.self::rp($bepHarian).'/hari.',
                'Tambah jam ramai (promo, member, turnamen) atau tekan pengeluaran tetap.');
        }

        // Proyeksi akhir bulan (bila periode = awal bulan s/d hari ini)
        $proyeksi = null;
        if ($dari->isSameDay($dari->copy()->startOfMonth()) && $sampai->isSameMonth(now()) && $sampai->isSameDay(today()->endOfDay())) {
            $jalan = (int) $dari->diffInDays(now()) + 1;
            $total = $dari->daysInMonth;
            $proyeksi = [
                'hari_jalan' => $jalan,
                'hari_bulan' => $total,
                'omzet' => (int) round($omzet / $jalan * $total),
                'laba' => (int) round($r['laba_bersih'] / $jalan * $total),
            ];
        }

        $aset = app(AsetService::class)->ringkasan();

        return [
            'r' => $r,
            'lalu' => $lalu,
            'hari' => $hari,
            'perubahan' => $perubahan,
            'margin_kotor' => $marginKotor,
            'margin_bersih' => $marginBersih,
            'rasio_beban' => $rasioBeban,
            'margin_fnb' => $marginFnb,
            'omzet_harian' => $omzetHarian,
            'bep_harian' => $bepHarian,
            'proyeksi' => $proyeksi,
            'lonjakan' => $lonjakan,
            'aset' => $aset['investasi'] > 0 ? $aset : null,
            'temuan' => $temuan,
        ];
    }

    /** @return Collection<string,int> kategori => total */
    private function pengeluaranKategori(CarbonInterface $dari, CarbonInterface $sampai): Collection
    {
        return Pengeluaran::query()
            ->where('status', 'aktif')
            ->whereNotIn('kategori', Pengeluaran::BUKAN_BEBAN)
            ->whereBetween('created_at', [$dari, $sampai])
            ->selectRaw('kategori, COALESCE(SUM(jumlah), 0) as total')
            ->groupBy('kategori')
            ->pluck('total', 'kategori')
            ->map(fn ($v) => (int) $v);
    }

    /* ============================ Operasional ============================= */

    public function operasional(CarbonInterface $dari, CarbonInterface $sampai): array
    {
        $hari = (int) $dari->diffInDays($sampai) + 1;
        $temuan = [];
        $cabangId = app(Tenancy::class)->cabangId();
        $jam = $cabangId ? BookingService::jamOperasionalCabang($cabangId) : ['buka' => '10:00', 'tutup' => '23:00'];
        [$jamBuka, $jamTutup] = [(int) substr($jam['buka'], 0, 2), (int) substr($jam['tutup'], 0, 2)];
        $lamaBuka = ($jamTutup <= $jamBuka ? $jamTutup + 24 : $jamTutup) - $jamBuka; // tutup lewat tengah malam
        $kapasitas = max(1, $lamaBuka * 3600 * $hari);

        // Utilisasi per unit = jam main ÷ jam buka
        $unit = $this->laporan->perUnit($dari, $sampai)
            ->map(fn ($u) => $u + ['utilisasi' => round($u['detik'] * 100 / $kapasitas, 1)])
            ->sortByDesc('utilisasi')->values();
        $rataUtil = (float) $unit->avg('utilisasi');

        foreach ($unit->filter(fn ($u) => $rataUtil >= 10 && $u['utilisasi'] < $rataUtil / 2) as $u) {
            $temuan[] = self::temuan('info', 'Operasional', "{$u['nama']} jarang dipakai ({$u['utilisasi']}%)",
                'Rata-rata semua unit '.round($rataUtil, 1).'%.',
                'Cek kondisi unit/stik/TV, posisinya, atau jadikan unit promo / turnamen.');
        }

        // Jam sepi di dalam jam buka
        $perJam = $this->laporan->jamSibuk($dari, $sampai);
        $maks = max($perJam);
        $jamBukaList = collect(range(0, $lamaBuka - 1))->map(fn ($i) => ($jamBuka + $i) % 24);
        $sepi = $maks >= 5 ? $jamBukaList->filter(fn ($j) => $perJam[$j] <= $maks * 0.2)->values() : collect();

        if ($sepi->count() >= 2) {
            $temuan[] = self::temuan('info', 'Operasional', 'Jam sepi: '.self::rentangJam($sepi),
                'Sesi dimulai di jam ini ≤ 20% dari jam tersibuk ('.sprintf('%02d:00', array_search($maks, $perJam, true)).').',
                'Coba paket happy hour / pelajar khusus jam sepi (Admin → Paket Harga), atau running text promo di TV.');
        }

        // Omzet per hari dalam seminggu (1 = Minggu di MySQL)
        $perHari = Transaksi::query()
            ->where('status', Transaksi::STATUS_LUNAS)->where('is_latihan', false)
            ->where('jenis', '!=', Transaksi::JENIS_TOP_UP)
            ->whereBetween('dibayar_pada', [$dari, $sampai])
            ->selectRaw('DAYOFWEEK(dibayar_pada) as h, COALESCE(SUM(total), 0) as omzet, COUNT(*) as n')
            ->groupBy('h')->get()->keyBy('h');
        $namaHari = [2 => 'Senin', 3 => 'Selasa', 4 => 'Rabu', 5 => 'Kamis', 6 => 'Jumat', 7 => 'Sabtu', 1 => 'Minggu'];
        $hariMinggu = collect($namaHari)->map(fn ($n, $h) => ['hari' => $n, 'omzet' => (int) ($perHari[$h]->omzet ?? 0)])->values();

        // Paket terlaris
        $paket = Sesi::query()
            ->whereBetween('mulai_pada', [$dari, $sampai])
            ->where('status', '!=', Sesi::STATUS_DIBATALKAN)
            ->selectRaw('paket_harga_id, mode, COUNT(*) as n')
            ->groupBy('paket_harga_id', 'mode')->orderByDesc('n')->limit(8)->get();
        $namaPaket = PaketHarga::query()->whereIn('id', $paket->pluck('paket_harga_id')->filter())->pluck('nama', 'id');
        $paket = $paket->map(fn ($p) => [
            'nama' => $p->paket_harga_id ? ($namaPaket[$p->paket_harga_id] ?? 'Paket') : ($p->mode === 'open' ? 'Open billing' : 'Durasi bebas'),
            'sesi' => (int) $p->n,
        ]);

        // Member: aktif 30 hari vs tidur
        $member = [
            'total' => Member::query()->where('is_active', true)->count(),
            'aktif' => Member::query()->where('is_active', true)->where('terakhir_kunjungan', '>=', now()->subDays(30))->count(),
            'tidur' => Member::query()->where('is_active', true)->where('terakhir_kunjungan', '<', now()->subDays(30))->count(),
        ];
        $omzetMember = (int) Transaksi::query()->where('status', Transaksi::STATUS_LUNAS)
            ->where('jenis', '!=', Transaksi::JENIS_TOP_UP)->whereNotNull('member_id')
            ->whereBetween('dibayar_pada', [$dari, $sampai])->sum('total');
        $omzetSemua = max(1, (int) Transaksi::query()->where('status', Transaksi::STATUS_LUNAS)
            ->where('jenis', '!=', Transaksi::JENIS_TOP_UP)->where('is_latihan', false)
            ->whereBetween('dibayar_pada', [$dari, $sampai])->sum('total'));
        $member['persen_omzet'] = round($omzetMember * 100 / $omzetSemua, 1);

        if ($member['tidur'] >= 5) {
            $temuan[] = self::temuan('info', 'Operasional', "{$member['tidur']} member tidak datang > 30 hari",
                "Dari {$member['total']} member aktif.",
                'Kirim promo / bonus poin lewat WhatsApp ke member yang lama tidak datang.');
        }

        return [
            'jam_buka' => $jam,
            'unit' => $unit,
            'rata_utilisasi' => round($rataUtil, 1),
            'per_jam' => $perJam,
            'jam_buka_list' => $jamBukaList,
            'hari_minggu' => $hariMinggu,
            'paket' => $paket,
            'member' => $member,
            'temuan' => $temuan,
        ];
    }

    /** [10,11,12,15] → "10–13, 15–16" */
    private static function rentangJam(Collection $jam): string
    {
        $bagian = [];
        $mulai = $akhir = null;

        foreach ($jam as $j) {
            if ($akhir !== null && $j === ($akhir + 1) % 24) {
                $akhir = $j;

                continue;
            }
            if ($mulai !== null) {
                $bagian[] = sprintf('%02d–%02d', $mulai, ($akhir + 1) % 24);
            }
            $mulai = $akhir = $j;
        }
        if ($mulai !== null) {
            $bagian[] = sprintf('%02d–%02d', $mulai, ($akhir + 1) % 24);
        }

        return implode(', ', $bagian);
    }

    /* ================================ Stok ================================ */

    public function stok(CarbonInterface $dari, CarbonInterface $sampai): array
    {
        $temuan = [];
        $stok = ProdukStok::query()
            ->with('produk:id,nama,harga_jual,lacak_stok,stok_minimum,is_active')
            ->get()
            ->filter(fn ($s) => $s->produk && $s->produk->is_active && $s->produk->lacak_stok);

        // Penjualan 14 & 30 hari terakhir (qty positif)
        $jual = fn (int $hari) => StokMutasi::query()
            ->where('jenis', 'penjualan')->where('created_at', '>=', now()->subDays($hari))
            ->selectRaw('produk_id, ABS(COALESCE(SUM(qty), 0)) as qty')
            ->groupBy('produk_id')->pluck('qty', 'produk_id');
        $jual14 = $jual(14);
        $jual30 = $jual(30);

        // Selisih opname di periode (minus = barang hilang)
        $opname = StokMutasi::query()
            ->where('jenis', 'opname')->whereBetween('created_at', [$dari, $sampai])
            ->selectRaw('produk_id, user_id, COALESCE(SUM(qty), 0) as qty')
            ->groupBy('produk_id', 'user_id')->get();

        $hpp = $stok->pluck('hpp_rata', 'produk_id');
        $namaProduk = $stok->mapWithKeys(fn ($s) => [$s->produk_id => $s->produk->nama]);
        $namaUser = User::query()->whereIn('id', $opname->pluck('user_id')->filter())->pluck('name', 'id');

        $hilang = $opname->filter(fn ($o) => $o->qty < 0)->groupBy('produk_id')->map(fn ($g, $id) => [
            'produk' => $namaProduk[$id] ?? '-',
            'qty' => (int) -$g->sum('qty'),
            'nilai' => (int) (-$g->sum('qty') * ($hpp[$id] ?? 0)),
            'oleh' => $g->pluck('user_id')->map(fn ($u) => $namaUser[$u] ?? '-')->unique()->implode(', '),
        ])->sortByDesc('nilai')->values();

        $nilaiHilang = (int) $hilang->sum('nilai');
        if ($nilaiHilang > 0) {
            $temuan[] = self::temuan($nilaiHilang >= 100_000 ? 'tinggi' : 'sedang', 'Stok', 'Barang hilang saat opname '.self::rp($nilaiHilang),
                $hilang->take(3)->map(fn ($h) => "{$h['produk']} −{$h['qty']}")->implode(', ').($hilang->count() > 3 ? ', …' : ''),
                'Opname lebih sering untuk produk ini, simpan di tempat terkunci, cocokkan dengan penjualan per shift.');
        }

        // Margin minus / tipis
        $margin = $stok->filter(fn ($s) => $s->hpp_rata > 0)->map(fn ($s) => [
            'produk' => $s->produk->nama,
            'harga_jual' => (int) $s->produk->harga_jual,
            'hpp' => (int) $s->hpp_rata,
            'margin' => round(($s->produk->harga_jual - $s->hpp_rata) * 100 / max(1, $s->produk->harga_jual), 1),
        ])->filter(fn ($m) => $m['margin'] < 15)->sortBy('margin')->values();

        foreach ($margin->filter(fn ($m) => $m['margin'] <= 0) as $m) {
            $temuan[] = self::temuan('tinggi', 'Stok', "{$m['produk']} dijual rugi",
                'Harga jual '.self::rp($m['harga_jual']).' ≤ harga pokok rata-rata '.self::rp($m['hpp']).'.',
                'Naikkan harga jual, atau koreksi harga pokok bila staf salah input (Stok → Riwayat → Koreksi).');
        }

        // Stok mati: ada stok tapi tidak terjual 30 hari
        $mati = $stok->filter(fn ($s) => $s->qty > 0 && ! isset($jual30[$s->produk_id]))->map(fn ($s) => [
            'produk' => $s->produk->nama,
            'qty' => (int) $s->qty,
            'nilai' => (int) ($s->qty * $s->hpp_rata),
        ])->sortByDesc('nilai')->values();

        if ($mati->sum('nilai') >= 100_000) {
            $temuan[] = self::temuan('info', 'Stok', 'Modal tertahan di stok mati '.self::rp((int) $mati->sum('nilai')),
                $mati->count().' produk tidak terjual 30 hari.', 'Buat promo bundling dengan sewa, atau berhenti membeli produk ini.');
        }

        // Prediksi habis dari rata-rata penjualan 14 hari
        $habis = $stok->map(function ($s) use ($jual14) {
            $rata = ($jual14[$s->produk_id] ?? 0) / 14;

            return [
                'produk' => $s->produk->nama,
                'qty' => (int) $s->qty,
                'per_hari' => round($rata, 1),
                'sisa_hari' => $rata > 0 ? (int) floor($s->qty / $rata) : null,
            ];
        })->filter(fn ($h) => $h['sisa_hari'] !== null && $h['sisa_hari'] <= 3)->sortBy('sisa_hari')->values();

        if ($habis->isNotEmpty()) {
            $temuan[] = self::temuan('sedang', 'Stok', $habis->count().' produk habis ≤ 3 hari lagi',
                $habis->take(4)->map(fn ($h) => "{$h['produk']} (±{$h['sisa_hari']} hari)")->implode(', '),
                'Belanja ulang sebelum habis; produk laris yang kosong = omzet hilang.');
        }

        return compact('hilang', 'margin', 'mati', 'habis', 'temuan');
    }
}
