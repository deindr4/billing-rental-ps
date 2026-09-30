<?php

namespace App\Services\Billing;

use App\Exceptions\BillingException;
use App\Models\Cabang;
use App\Models\Pengaturan;
use App\Models\Produk;
use App\Models\Sesi;
use App\Models\SesiLog;
use App\Models\Transaksi;
use App\Models\TransaksiItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Penjualan F&B: jual langsung (transaksi POS) atau ditambahkan ke tagihan sesi unit.
 * Pembayaran memakai BillingService::bayar(), pembatalan memakai BillingService::batalkan().
 */
final class PosService
{
    public function __construct(
        private NomorTransaksi $nomor,
        private ShiftService $shift,
        private StokService $stok,
    ) {}

    /**
     * Jual langsung. Transaksi dibuat berstatus belum_bayar, lalu dibayar lewat dialog pembayaran.
     *
     * @param  array<string,int>  $keranjang  [produk_id => qty]
     */
    public function jual(Cabang $cabang, User $user, array $keranjang, ?string $pelanggan = null): Transaksi
    {
        $this->validasiKeranjang($keranjang);

        return DB::transaction(function () use ($cabang, $user, $keranjang, $pelanggan) {
            $shift = $this->shift->wajibAktif($user, $cabang->id);

            $transaksi = Transaksi::create([
                'tenant_id' => $cabang->tenant_id,
                'cabang_id' => $cabang->id,
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'nomor' => $this->nomor->buat('POS', $cabang),
                'jenis' => Transaksi::JENIS_POS,
                'status' => Transaksi::STATUS_BELUM_BAYAR,
                'pelanggan_nama' => $pelanggan ?: null,
            ]);

            $this->tambahItem($transaksi, $keranjang, $user);
            $transaksi->hitungUlang();

            return $transaksi;
        });
    }

    /**
     * Tambahkan F&B ke tagihan transaksi yang sudah ada (misal sesi rental).
     *
     * @param  array<string,int>  $keranjang  [produk_id => qty]
     */
    public function tambahKeTagihan(Transaksi $transaksi, User $user, array $keranjang): Transaksi
    {
        $this->validasiKeranjang($keranjang);

        return DB::transaction(function () use ($transaksi, $user, $keranjang) {
            $transaksi = Transaksi::withoutGlobalScopes()->whereKey($transaksi->id)->lockForUpdate()->firstOrFail();

            if ($transaksi->isDibatalkan()) {
                throw new BillingException('Transaksi sudah dibatalkan.');
            }

            $sesi = Sesi::withoutGlobalScopes()->where('transaksi_id', $transaksi->id)->first();

            if ($sesi && $sesi->status === Sesi::STATUS_DIBATALKAN) {
                throw new BillingException('Sesi sudah dibatalkan.');
            }

            if ($transaksi->isLunas() && (! $sesi || ! $sesi->isAktif())) {
                throw new BillingException('Transaksi sudah selesai dan lunas. Buat penjualan baru.');
            }

            $this->shift->wajibAktif($user, $transaksi->cabang_id);

            $this->tambahItem($transaksi, $keranjang, $user);
            $transaksi->hitungUlang();

            if ($transaksi->isLunas() && $transaksi->sisaTagihan() > 0) {
                $transaksi->update(['status' => Transaksi::STATUS_BELUM_BAYAR]);
                $transaksi->hitungUlang(); // diskon tier member ikut item baru
            }

            if ($sesi) {
                $sesi->versi_tagihan = $sesi->versi_tagihan + 1;
                $sesi->save();

                SesiLog::create([
                    'tenant_id' => $sesi->tenant_id,
                    'cabang_id' => $sesi->cabang_id,
                    'sesi_id' => $sesi->id,
                    'user_id' => $user->id,
                    'jenis' => 'tambah_fnb',
                    'data' => ['item' => count($keranjang), 'total' => $transaksi->total],
                ]);
            }

            return $transaksi;
        });
    }

    /** @param  array<string,int>  $keranjang */
    private function tambahItem(Transaksi $transaksi, array $keranjang, User $user): void
    {
        $produkList = Produk::withoutGlobalScopes()
            ->where('tenant_id', $transaksi->tenant_id)
            ->whereIn('id', array_keys($keranjang))
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $izinMinus = (bool) Pengaturan::ambil('pos.izinkan_stok_minus', false, $transaksi->cabang_id);

        foreach ($keranjang as $produkId => $qty) {
            $produk = $produkList->get($produkId)
                ?? throw new BillingException('Ada produk yang tidak ditemukan atau sudah nonaktif.');

            $hpp = 0;

            if ($produk->lacak_stok) {
                $stok = $this->stok->kunci($produk, $transaksi->cabang_id);

                if (! $izinMinus && $stok->qty < $qty) {
                    throw new BillingException("Stok {$produk->nama} tidak cukup (sisa {$stok->qty}).");
                }

                $hpp = $stok->hpp_rata;
            }

            $item = TransaksiItem::create([
                'tenant_id' => $transaksi->tenant_id,
                'cabang_id' => $transaksi->cabang_id,
                'transaksi_id' => $transaksi->id,
                'jenis' => TransaksiItem::JENIS_PRODUK,
                'referensi_type' => $produk->getMorphClass(),
                'referensi_id' => $produk->id,
                'nama' => $produk->nama,
                'qty' => $qty,
                'harga_satuan' => $produk->harga_jual,
                'hpp_satuan' => $hpp,
                'subtotal' => $produk->harga_jual * $qty,
            ]);

            if ($produk->lacak_stok) {
                $this->stok->catat($produk, $transaksi->cabang_id, -$qty, 'penjualan', $user, $item, "Penjualan {$transaksi->nomor}");
            }
        }
    }

    /** @param  array<string,int>  $keranjang */
    private function validasiKeranjang(array $keranjang): void
    {
        if ($keranjang === []) {
            throw new BillingException('Keranjang masih kosong.');
        }

        foreach ($keranjang as $qty) {
            if (! is_int($qty) || $qty < 1 || $qty > 999) {
                throw new BillingException('Jumlah item tidak valid (1 sampai 999).');
            }
        }
    }
}
