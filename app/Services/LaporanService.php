<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Cabang;
use App\Models\Pembayaran;
use App\Models\Pengeluaran;
use App\Models\Sesi;
use App\Models\Transaksi;
use App\Models\TransaksiItem;
use App\Models\Unit;
use App\Models\User;
use App\Support\Tenancy;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Perhitungan laporan untuk cabang aktif (scope tenant & cabang otomatis).
 * Dipakai halaman Laporan, laporan tutup kas, Telegram/WhatsApp, dan PDF.
 *
 * Pendapatan diakui saat transaksi LUNAS (tanggal dibayar).
 */
final class LaporanService
{
    /** Ringkasan keuangan satu periode */
    public function ringkasan(CarbonInterface $dari, CarbonInterface $sampai): array
    {
        $lunas = $this->transaksiLunas($dari, $sampai);

        $trx = (clone $lunas)
            ->selectRaw('COUNT(*) as jumlah')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as kotor')
            ->selectRaw('COALESCE(SUM(total_diskon), 0) as diskon')
            ->selectRaw('COALESCE(SUM(total), 0) as bersih')
            ->first();

        $perJenis = TransaksiItem::query()
            ->whereIn('transaksi_id', (clone $lunas)->select('id'))
            ->selectRaw('jenis')
            ->selectRaw('COALESCE(SUM(subtotal), 0) as total')
            ->selectRaw('COALESCE(SUM(qty * hpp_satuan), 0) as hpp')
            ->groupBy('jenis')
            ->get()
            ->keyBy('jenis');

        $sewa = (int) (($perJenis['sewa']->total ?? 0) + ($perJenis['tambah_waktu']->total ?? 0));
        $fnb = (int) ($perJenis['produk']->total ?? 0);
        $lainnya = (int) ($perJenis['lainnya']->total ?? 0);
        $hpp = (int) ($perJenis['produk']->hpp ?? 0);

        $pengeluaranAktif = Pengeluaran::query()
            ->where('status', 'aktif')
            ->whereBetween('created_at', [$dari, $sampai]);

        $beban = (int) (clone $pengeluaranAktif)->whereNotIn('kategori', Pengeluaran::BUKAN_BEBAN)->sum('jumlah');
        $belanjaStok = (int) (clone $pengeluaranAktif)->whereIn('kategori', Pengeluaran::BUKAN_BEBAN)->sum('jumlah');

        $bersih = (int) $trx->bersih;
        $labaKotor = $bersih - $hpp;

        $batal = Transaksi::query()
            ->where('status', Transaksi::STATUS_DIBATALKAN)
            ->where('jenis', '!=', Transaksi::JENIS_TOP_UP)
            ->whereBetween('dibatalkan_pada', [$dari, $sampai])
            ->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(total), 0) as nilai')
            ->first();

        // Top up saldo member = titipan uang (bukan omzet); omzet diakui saat saldo dipakai
        $topup = (int) Transaksi::query()
            ->where('jenis', Transaksi::JENIS_TOP_UP)
            ->where('status', Transaksi::STATUS_LUNAS)
            ->where('is_latihan', false)
            ->whereBetween('dibayar_pada', [$dari, $sampai])
            ->sum('total');

        return [
            'jumlah_transaksi' => (int) $trx->jumlah,
            'omzet_kotor' => (int) $trx->kotor,
            'diskon' => (int) $trx->diskon,
            'omzet_bersih' => $bersih,
            'rata_rata' => $trx->jumlah > 0 ? intdiv($bersih, (int) $trx->jumlah) : 0,
            'pendapatan_sewa' => $sewa,
            'pendapatan_fnb' => $fnb,
            'pendapatan_lainnya' => $lainnya,
            'hpp' => $hpp,
            'laba_kotor' => $labaKotor,
            'beban' => $beban,
            'belanja_stok' => $belanjaStok,
            'laba_bersih' => $labaKotor - $beban,
            'batal_jumlah' => (int) $batal->jumlah,
            'batal_nilai' => (int) $batal->nilai,
            'topup' => $topup,
        ];
    }

    /** Pembayaran per metode */
    public function perMetode(CarbonInterface $dari, CarbonInterface $sampai): Collection
    {
        return Pembayaran::query()
            ->where('status', 'sukses')
            ->whereBetween('dibayar_pada', [$dari, $sampai])
            ->selectRaw('metode, COUNT(*) as jumlah, COALESCE(SUM(jumlah), 0) as total')
            ->groupBy('metode')
            ->orderByDesc('total')
            ->get();
    }

    /** Jumlah sesi dimulai per jam (0-23) */
    public function jamSibuk(CarbonInterface $dari, CarbonInterface $sampai): array
    {
        $data = Sesi::query()
            ->whereBetween('mulai_pada', [$dari, $sampai])
            ->where('status', '!=', Sesi::STATUS_DIBATALKAN)
            ->selectRaw('HOUR(mulai_pada) as jam, COUNT(*) as jumlah')
            ->groupBy('jam')
            ->pluck('jumlah', 'jam');

        return collect(range(0, 23))->mapWithKeys(fn ($j) => [$j => (int) ($data[$j] ?? 0)])->all();
    }

    /** Jam main & pendapatan sewa per unit */
    public function perUnit(CarbonInterface $dari, CarbonInterface $sampai): Collection
    {
        $jamMain = Sesi::query()
            ->whereBetween('mulai_pada', [$dari, $sampai])
            ->where('status', Sesi::STATUS_SELESAI)
            ->selectRaw('unit_id')
            ->selectRaw('COUNT(*) as sesi')
            ->selectRaw('COALESCE(SUM(GREATEST(CAST(TIMESTAMPDIFF(SECOND, mulai_pada, selesai_pada) AS SIGNED) - CAST(total_jeda_detik AS SIGNED), 0)), 0) as detik')
            ->groupBy('unit_id')
            ->get()
            ->keyBy('unit_id');

        $pendapatan = TransaksiItem::query()
            ->join('transaksi', 'transaksi.id', '=', 'transaksi_item.transaksi_id')
            ->whereIn('transaksi_item.transaksi_id', $this->transaksiLunas($dari, $sampai)->select('id'))
            ->whereIn('transaksi_item.jenis', ['sewa', 'tambah_waktu'])
            ->selectRaw('transaksi.unit_id as unit_id, COALESCE(SUM(transaksi_item.subtotal), 0) as total')
            ->groupBy('transaksi.unit_id')
            ->pluck('total', 'unit_id');

        $kodeCabang = Cabang::query()->pluck('kode', 'id');

        return Unit::query()
            ->orderBy('cabang_id')
            ->orderBy('urutan')
            ->orderBy('kode')
            ->get(['id', 'cabang_id', 'kode', 'nama'])
            ->map(fn (Unit $u) => [
                'cabang' => $kodeCabang[$u->cabang_id] ?? '',
                'kode' => $u->kode,
                'nama' => $u->nama,
                'sesi' => (int) ($jamMain[$u->id]->sesi ?? 0),
                'detik' => (int) ($jamMain[$u->id]->detik ?? 0),
                'pendapatan' => (int) ($pendapatan[$u->id] ?? 0),
            ]);
    }

    /** Produk terlaris */
    public function produkTerlaris(CarbonInterface $dari, CarbonInterface $sampai, int $batas = 10): Collection
    {
        return TransaksiItem::query()
            ->whereIn('transaksi_id', $this->transaksiLunas($dari, $sampai)->select('id'))
            ->where('jenis', TransaksiItem::JENIS_PRODUK)
            ->selectRaw('nama')
            ->selectRaw('SUM(qty) as qty')
            ->selectRaw('SUM(subtotal) as omzet')
            ->selectRaw('SUM(qty * hpp_satuan) as hpp')
            ->groupBy('nama')
            ->orderByDesc('qty')
            ->limit($batas)
            ->get();
    }

    /** Transaksi & omzet per kasir */
    public function perOperator(CarbonInterface $dari, CarbonInterface $sampai): Collection
    {
        $lunas = $this->transaksiLunas($dari, $sampai)
            ->selectRaw('user_id, COUNT(*) as jumlah, COALESCE(SUM(total), 0) as omzet')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $batal = Transaksi::query()
            ->where('status', Transaksi::STATUS_DIBATALKAN)
            ->where('jenis', '!=', Transaksi::JENIS_TOP_UP)
            ->whereBetween('dibatalkan_pada', [$dari, $sampai])
            ->selectRaw('user_id, COUNT(*) as jumlah')
            ->groupBy('user_id')
            ->pluck('jumlah', 'user_id');

        $ids = $lunas->keys()->merge($batal->keys())->unique();
        $nama = User::whereIn('id', $ids)->pluck('name', 'id');

        return $ids->map(fn ($id) => [
            'nama' => $nama[$id] ?? '-',
            'jumlah' => (int) ($lunas[$id]->jumlah ?? 0),
            'omzet' => (int) ($lunas[$id]->omzet ?? 0),
            'batal' => (int) ($batal[$id] ?? 0),
        ])->sortByDesc('omzet')->values();
    }

    /**
     * Log anomali (void, waktu gratis, bypass TV, PIN salah, selisih kas, koreksi member) di cabang aktif.
     *
     * @return array{per_aksi: Collection, terbaru: Collection}
     */
    public function anomali(CarbonInterface $dari, CarbonInterface $sampai, int $batas = 15): array
    {
        $cabangId = app(Tenancy::class)->cabangId();

        $dasar = AuditLog::query()
            ->where('anomali', true)
            ->whereBetween('created_at', [$dari, $sampai])
            ->where(fn ($q) => $q->where('cabang_id', $cabangId)->orWhereNull('cabang_id'));

        return [
            'per_aksi' => (clone $dasar)->selectRaw('aksi, COUNT(*) as jumlah')->groupBy('aksi')->orderByDesc('jumlah')->get(),
            'terbaru' => (clone $dasar)->with('user:id,name')->latest('created_at')->limit($batas)->get(),
        ];
    }

    /** Pengeluaran per kategori */
    public function pengeluaranPerKategori(CarbonInterface $dari, CarbonInterface $sampai): Collection
    {
        return Pengeluaran::query()
            ->where('status', 'aktif')
            ->whereBetween('created_at', [$dari, $sampai])
            ->selectRaw('kategori, COUNT(*) as jumlah, COALESCE(SUM(jumlah), 0) as total')
            ->groupBy('kategori')
            ->orderByDesc('total')
            ->get();
    }

    private function transaksiLunas(CarbonInterface $dari, CarbonInterface $sampai)
    {
        return Transaksi::query()
            ->where('status', Transaksi::STATUS_LUNAS)
            ->where('jenis', '!=', Transaksi::JENIS_TOP_UP)
            ->where('is_latihan', false)
            ->whereBetween('dibayar_pada', [$dari, $sampai]);
    }
}
