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
use App\Support\Audit;
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

            $itemBaru = $this->tambahItem($transaksi, $keranjang, $user);
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
                    // item_ids: siapa & kapan item dicatat (aturan batal tanpa PIN)
                    'data' => ['item' => count($keranjang), 'total' => $transaksi->total, 'item_ids' => $itemBaru],
                ]);
            }

            return $transaksi;
        });
    }

    /**
     * Batalkan F&B yang salah dicatat di tagihan (sebagian qty atau semua). Item tidak dihapus (dilindungi database):
     * qty dikurangi; bila habis → qty 0, Rp0, "(dibatalkan)" & qty asli di catatan. Stok dikembalikan.
     * Ditolak bila tagihan sudah terbayar melebihi total baru (batalkan lewat menu Transaksi supaya uang tercatat kembali).
     */
    public function batalItem(string $itemId, User $user, int $qty, string $alasan): Transaksi
    {
        if (mb_strlen(trim($alasan)) < 3) {
            throw new BillingException('Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($itemId, $user, $qty, $alasan) {
            $item = TransaksiItem::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->find($itemId)
                ?? throw new BillingException('Item tidak ditemukan.');
            $transaksi = Transaksi::withoutGlobalScopes()->whereKey($item->transaksi_id)->lockForUpdate()->firstOrFail();
            $item->refresh();

            if ($transaksi->isDibatalkan()) {
                throw new BillingException('Transaksi sudah dibatalkan.');
            }

            if ($item->jenis !== TransaksiItem::JENIS_PRODUK || $item->qty < 1) {
                throw new BillingException('Item ini tidak bisa dibatalkan (bukan F&B atau sudah dibatalkan).');
            }

            if ($qty < 1 || $qty > $item->qty) {
                throw new BillingException("Jumlah batal 1 sampai {$item->qty}.");
            }

            $this->shift->wajibAktif($user, $transaksi->cabang_id);

            $sisa = $item->qty - $qty;
            $catatan = trim(($item->catatan ? $item->catatan.' | ' : '')."Batal {$qty}x: ".trim($alasan));
            $item->update($sisa > 0
                ? ['qty' => $sisa, 'subtotal' => $item->harga_satuan * $sisa, 'catatan' => $catatan]
                : ['qty' => 0, 'subtotal' => 0, 'nama' => $item->nama.' (dibatalkan)', 'catatan' => $catatan]);

            $produk = Produk::withoutGlobalScopes()->find($item->referensi_id);

            if ($produk?->lacak_stok) {
                $this->stok->catat($produk, $transaksi->cabang_id, $qty, 'pembatalan', $user, $item, "Batal F&B {$transaksi->nomor}");
            }

            $transaksi->hitungUlang();

            if ($transaksi->totalDibayar() > $transaksi->total) {
                throw new BillingException('Item ini sudah dibayar. Batalkan lewat menu Transaksi supaya uangnya tercatat dikembalikan.');
            }

            $nilai = $item->harga_satuan * $qty;
            $sesi = Sesi::withoutGlobalScopes()->where('transaksi_id', $transaksi->id)->first();

            if ($sesi) {
                $sesi->versi_tagihan = $sesi->versi_tagihan + 1; // TV ikut memperbarui rincian
                $sesi->save();

                SesiLog::create([
                    'tenant_id' => $sesi->tenant_id,
                    'cabang_id' => $sesi->cabang_id,
                    'sesi_id' => $sesi->id,
                    'user_id' => $user->id,
                    'jenis' => 'batal_fnb',
                    'data' => ['item_id' => $item->id, 'nama' => $produk?->nama ?? $item->nama, 'qty' => $qty, 'nilai' => $nilai, 'alasan' => trim($alasan)],
                ]);
            }

            Audit::catat('batal_fnb', "Batal {$qty}x ".($produk?->nama ?? $item->nama)." di {$transaksi->nomor} (Rp ".number_format($nilai, 0, ',', '.')."): {$alasan}", $transaksi, [
                'item_id' => $item->id, 'qty' => $qty, 'nilai' => $nilai, 'alasan' => $alasan,
            ], userId: $user->id);

            return $transaksi;
        });
    }

    /** Pencatat item F&B di tagihan sesi (dari log tambah_fnb) — untuk aturan batal tanpa PIN */
    public static function pencatatItem(string $sesiId, string $itemId): ?SesiLog
    {
        return SesiLog::withoutGlobalScopes()->where('sesi_id', $sesiId)->where('jenis', 'tambah_fnb')->latest()->get()
            ->first(fn (SesiLog $l) => in_array($itemId, $l->data['item_ids'] ?? [], true));
    }

    /**
     * @param  array<string,int>  $keranjang
     * @return array<int,string> id item yang dibuat
     */
    private function tambahItem(Transaksi $transaksi, array $keranjang, User $user): array
    {
        $dibuat = [];
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

            $dibuat[] = $item->id;
        }

        return $dibuat;
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
