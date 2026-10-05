<?php

namespace App\Services\Billing;

use App\Exceptions\BillingException;
use App\Models\Produk;
use App\Models\ProdukStok;
use App\Models\StokMutasi;
use App\Models\TransaksiItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Semua perubahan stok lewat sini: dicatat sebagai mutasi (+ masuk / - keluar),
 * lalu saldo di produk_stok diperbarui.
 */
final class StokService
{
    public function catat(
        Produk $produk,
        string $cabangId,
        int $qty,
        string $jenis,
        ?User $user = null,
        ?Model $sumber = null,
        ?string $keterangan = null,
        ?int $hargaPokok = null,
    ): StokMutasi {
        return DB::transaction(function () use ($produk, $cabangId, $qty, $jenis, $user, $sumber, $keterangan, $hargaPokok) {
            $stok = $this->kunci($produk, $cabangId);

            // Harga pokok rata-rata tertimbang saat stok masuk
            if ($jenis === 'masuk' && $qty > 0 && $hargaPokok !== null) {
                $lama = max(0, $stok->qty);
                $total = $lama + $qty;

                $stok->hpp_rata = $lama > 0
                    ? intdiv($lama * $stok->hpp_rata + $qty * $hargaPokok + intdiv($total, 2), $total)
                    : $hargaPokok;
            }

            $stok->qty = $stok->qty + $qty;
            $stok->save();

            return StokMutasi::create([
                'tenant_id' => $produk->tenant_id,
                'cabang_id' => $cabangId,
                'produk_id' => $produk->id,
                'user_id' => $user?->id,
                'jenis' => $jenis,
                'qty' => $qty,
                'harga_pokok' => $hargaPokok,
                'sumber_type' => $sumber?->getMorphClass(),
                'sumber_id' => $sumber?->getKey(),
                'keterangan' => $keterangan,
            ]);
        });
    }

    /**
     * Koreksi harga pokok satu stok masuk yang salah input (owner), lalu HPP rata-rata & HPP penjualan sesudahnya
     * dihitung ulang dari riwayat, supaya laporan laba benar. Jumlah stok tidak berubah.
     *
     * @return array{hpp_lama:int, hpp_baru:int, item:int} item = jumlah baris penjualan yang HPP-nya diperbaiki
     */
    public function koreksiHargaPokok(StokMutasi $mutasi, int $hargaBaru, User $user, string $alasan): array
    {
        if ($mutasi->jenis !== 'masuk' || $mutasi->qty <= 0) {
            throw new BillingException('Hanya harga pokok stok masuk yang bisa dikoreksi.');
        }

        if ($hargaBaru < 0) {
            throw new BillingException('Harga pokok tidak boleh negatif.');
        }

        if (mb_strlen(trim($alasan)) < 3) {
            throw new BillingException('Alasan koreksi wajib diisi.');
        }

        return DB::transaction(function () use ($mutasi, $hargaBaru, $user, $alasan) {
            $produk = Produk::withoutGlobalScopes()->findOrFail($mutasi->produk_id);
            $stok = $this->kunci($produk, $mutasi->cabang_id);
            $hppLama = (int) $stok->hpp_rata;
            $hargaLama = (int) $mutasi->harga_pokok;

            $mutasi->forceFill([
                'harga_pokok' => $hargaBaru,
                'keterangan' => trim(($mutasi->keterangan ? $mutasi->keterangan.' | ' : '')
                    ."Harga pokok dikoreksi Rp{$hargaLama} → Rp{$hargaBaru}: ".trim($alasan)),
            ])->save();

            $item = $this->hitungUlangHpp($produk, $mutasi->cabang_id, $stok);

            Audit::catat('koreksi_hpp', "Koreksi harga pokok {$produk->nama}: Rp".number_format($hargaLama, 0, ',', '.')
                .' → Rp'.number_format($hargaBaru, 0, ',', '.')." ({$mutasi->qty} pcs, {$item} penjualan diperbarui): {$alasan}", $mutasi, [
                    'produk_id' => $produk->id, 'harga_lama' => $hargaLama, 'harga_baru' => $hargaBaru,
                    'hpp_rata_lama' => $hppLama, 'hpp_rata_baru' => (int) $stok->hpp_rata, 'item' => $item,
                ], userId: $user->id);

            return ['hpp_lama' => $hppLama, 'hpp_baru' => (int) $stok->hpp_rata, 'item' => $item];
        });
    }

    /**
     * Putar ulang riwayat mutasi produk di cabang (urut waktu) dengan rumus yang sama seperti catat():
     * HPP rata-rata tertimbang saat stok masuk; tiap penjualan memakai HPP saat itu.
     * Return jumlah item penjualan yang hpp_satuan-nya berubah.
     */
    public function hitungUlangHpp(Produk $produk, string $cabangId, ProdukStok $stok): int
    {
        $qty = 0;
        $hpp = 0;
        $berubah = 0;
        $jenisItem = (new TransaksiItem)->getMorphClass();

        $mutasi = StokMutasi::withoutGlobalScopes()
            ->where('produk_id', $produk->id)->where('cabang_id', $cabangId)
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'jenis', 'qty', 'harga_pokok', 'sumber_type', 'sumber_id']);

        foreach ($mutasi as $m) {
            if ($m->jenis === 'masuk' && $m->qty > 0 && $m->harga_pokok !== null) {
                $lama = max(0, $qty);
                $total = $lama + $m->qty;
                $hpp = $lama > 0 ? intdiv($lama * $hpp + $m->qty * $m->harga_pokok + intdiv($total, 2), $total) : (int) $m->harga_pokok;
            } elseif ($m->jenis === 'penjualan' && $m->sumber_type === $jenisItem && $m->sumber_id) {
                $berubah += TransaksiItem::withoutGlobalScopes()->whereKey($m->sumber_id)
                    ->where('hpp_satuan', '!=', $hpp)->update(['hpp_satuan' => $hpp]);
            }

            $qty += $m->qty;
        }

        $stok->hpp_rata = $hpp;
        $stok->save();

        return $berubah;
    }

    /** Ambil & kunci baris saldo stok (dibuat otomatis jika belum ada). */
    public function kunci(Produk $produk, string $cabangId): ProdukStok
    {
        ProdukStok::withoutGlobalScopes()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $produk->tenant_id,
            'cabang_id' => $cabangId,
            'produk_id' => $produk->id,
            'qty' => 0,
            'hpp_rata' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ProdukStok::withoutGlobalScopes()
            ->where('cabang_id', $cabangId)
            ->where('produk_id', $produk->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
