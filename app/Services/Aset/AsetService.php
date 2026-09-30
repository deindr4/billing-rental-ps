<?php

namespace App\Services\Aset;

use App\Exceptions\BillingException;
use App\Models\Aset;
use App\Models\Maintenance;
use App\Models\ModalMutasi;
use App\Models\Sesi;
use App\Models\Transaksi;
use App\Models\TransaksiItem;
use App\Services\LaporanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aset, penyusutan, dan ROI (Stitch 04). Perhitungan per cabang aktif (scope otomatis).
 */
final class AsetService
{
    public function __construct(private LaporanService $laporan) {}

    /** @param array<string,mixed> $data */
    public function simpan(array $data, ?Aset $aset = null): Aset
    {
        $data = $this->validasi($data);

        if ($aset) {
            $aset->update($data);

            return $aset;
        }

        return Aset::create($data + ['status' => 'aktif']);
    }

    /** Aset dijual / dibuang: berhenti disusutkan */
    public function lepas(Aset $aset, string $tanggal, ?int $nilaiLepas, ?string $catatan = null): Aset
    {
        if ($aset->status === 'dilepas') {
            throw new BillingException('Aset sudah dilepas.');
        }

        $tgl = Carbon::parse($tanggal);

        if ($tgl->lt($aset->tanggal_beli)) {
            throw new BillingException('Tanggal lepas tidak boleh sebelum tanggal beli.');
        }

        $aset->update([
            'status' => 'dilepas',
            'dilepas_pada' => $tgl->toDateString(),
            'nilai_lepas' => $nilaiLepas !== null ? max(0, $nilaiLepas) : null,
            'catatan' => trim(($aset->catatan ? $aset->catatan."\n" : '').($catatan ?? '')) ?: null,
        ]);

        return $aset;
    }

    /**
     * Omzet sewa & jam operasional per unit sejak tanggal tertentu.
     *
     * @param  array<string,string>  $sejak  unit_id => tanggal (Y-m-d)
     * @return array<string, array{omzet:int, detik:int}>
     */
    public function statistikUnit(array $sejak): array
    {
        if ($sejak === []) {
            return [];
        }

        $hasil = [];

        foreach ($sejak as $unitId => $tanggal) {
            $omzet = (int) TransaksiItem::query()
                ->join('transaksi', 'transaksi.id', '=', 'transaksi_item.transaksi_id')
                ->where('transaksi.unit_id', $unitId)
                ->where('transaksi.status', Transaksi::STATUS_LUNAS)
                ->where('transaksi.is_latihan', false)
                ->where('transaksi.dibayar_pada', '>=', $tanggal)
                ->whereIn('transaksi_item.jenis', [TransaksiItem::JENIS_SEWA, TransaksiItem::JENIS_TAMBAH_WAKTU])
                ->sum('transaksi_item.subtotal');

            $detik = (int) Sesi::query()
                ->where('unit_id', $unitId)
                ->where('status', Sesi::STATUS_SELESAI)
                ->whereNotNull('selesai_pada')
                ->where('mulai_pada', '>=', $tanggal)
                ->selectRaw('COALESCE(SUM(GREATEST(0, TIMESTAMPDIFF(SECOND, mulai_pada, selesai_pada) - total_jeda_detik)), 0) as detik')
                ->value('detik');

            $hasil[$unitId] = ['omzet' => $omzet, 'detik' => $detik];
        }

        return $hasil;
    }

    /** Ringkasan kartu atas Stitch 04: investasi, nilai buku, laba, ROI, balik modal */
    public function ringkasan(): array
    {
        $aset = Aset::query()->dimiliki()->get();

        $investasi = (int) $aset->sum('harga_perolehan');
        $nilaiBuku = (int) $aset->sum(fn (Aset $a) => $a->nilaiBuku());

        $awal = Transaksi::query()->where('status', Transaksi::STATUS_LUNAS)->min('dibayar_pada');
        $laba = $awal ? $this->laporan->ringkasan(Carbon::parse($awal)->startOfDay(), now())['laba_bersih'] : 0;

        // Rata-rata laba per bulan dari 90 hari terakhir untuk perkiraan balik modal
        $laba90 = $this->laporan->ringkasan(now()->subDays(90)->startOfDay(), now())['laba_bersih'];
        $perBulan = intdiv(max(0, $laba90), 3);

        $roi = $investasi > 0 ? round($laba * 100 / $investasi, 1) : 0.0;
        $kurang = max(0, $investasi - $laba);

        $modal = ModalMutasi::query()->where('status', 'aktif')
            ->selectRaw('jenis, COALESCE(SUM(jumlah), 0) as total')->groupBy('jenis')->pluck('total', 'jenis');

        return [
            'jumlah_aset' => $aset->count(),
            'investasi' => $investasi,
            'nilai_buku' => $nilaiBuku,
            'persen_susut' => $investasi > 0 ? (int) round(($investasi - $nilaiBuku) * 100 / $investasi) : 0,
            'laba' => $laba,
            'laba_per_bulan' => $perBulan,
            'roi' => $roi,
            'balik_modal_bulan' => $kurang === 0 ? 0 : ($perBulan > 0 ? (int) ceil($kurang / $perBulan) : null),
            'modal_masuk' => (int) ($modal['modal'] ?? 0),
            'prive' => (int) ($modal['prive'] ?? 0),
            'ringkas_kategori' => $aset->groupBy('kategori')->map->count()->all(),
        ];
    }

    /** Aset yang jadwal servisnya sudah dekat / lewat & belum ada tiket terbuka */
    public function jatuhTempoServis(): Collection
    {
        $terbuka = Maintenance::query()->terbuka()->whereNotNull('aset_id')->pluck('aset_id')->all();

        return Aset::query()
            ->dimiliki()
            ->whereNotNull('interval_servis_hari')
            ->whereNotIn('id', $terbuka)
            ->with('unit:id,nama')
            ->get()
            ->filter(fn (Aset $a) => $a->jatuhTempoServis())
            ->sortBy(fn (Aset $a) => $a->servisBerikutnya())
            ->values();
    }

    /** Baris laporan penyusutan (CSV/PDF) */
    public function laporanPenyusutan(): Collection
    {
        return Aset::query()->with('unit:id,nama')->orderBy('kategori')->orderBy('tanggal_beli')->get()
            ->map(fn (Aset $a) => [
                'aset' => $a,
                'per_bulan' => $a->penyusutanPerBulan(),
                'akumulasi' => $a->akumulasiPenyusutan(),
                'nilai_buku' => $a->nilaiBuku(),
            ]);
    }

    private function validasi(array $data): array
    {
        if (! array_key_exists($data['kategori'] ?? '', Aset::KATEGORI)) {
            throw new BillingException('Kategori aset tidak valid.');
        }

        $harga = (int) ($data['harga_perolehan'] ?? 0);
        $sisa = (int) ($data['nilai_sisa'] ?? 0);

        if ($harga <= 0) {
            throw new BillingException('Harga perolehan wajib diisi.');
        }

        if ($sisa < 0 || $sisa >= $harga) {
            throw new BillingException('Nilai sisa harus lebih kecil dari harga perolehan.');
        }

        $data['umur_bulan'] = max(1, min(240, (int) ($data['umur_bulan'] ?? Aset::UMUR_BAWAAN[$data['kategori']])));
        $data['interval_servis_hari'] = ((int) ($data['interval_servis_hari'] ?? 0)) ?: null;

        return array_intersect_key($data, array_flip((new Aset)->getFillable()));
    }

    /** Hapus aset yang salah input (hanya jika belum punya riwayat maintenance) */
    public function hapus(Aset $aset): void
    {
        DB::transaction(function () use ($aset) {
            if (Maintenance::query()->where('aset_id', $aset->id)->exists()) {
                throw new BillingException('Aset sudah punya riwayat maintenance. Gunakan "Lepas aset" jika dijual/dibuang.');
            }

            $aset->delete();
        });
    }
}
