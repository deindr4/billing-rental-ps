<?php

namespace App\Services\Billing;

use App\Exceptions\BillingException;
use App\Models\Cabang;
use App\Models\Produk;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stok masuk (belanja barang) dan stok opname (hitung fisik).
 */
final class InventoriService
{
    public function __construct(
        private StokService $stok,
        private NomorTransaksi $nomor,
        private PengeluaranService $pengeluaran,
    ) {}

    /**
     * @param  array<int, array{produk_id:string, qty:int|string|null, harga:int|string|null}>  $baris
     * @return string nomor dokumen stok masuk
     */
    public function stokMasuk(Cabang $cabang, User $user, array $baris, ?string $keterangan = null, bool $dariKas = false): string
    {
        if ($baris === []) {
            throw new BillingException('Belum ada barang yang diisi.');
        }

        return DB::transaction(function () use ($cabang, $user, $baris, $keterangan, $dariKas) {
            $nomor = $this->nomor->buat('STM', $cabang);
            $produk = $this->produk($cabang, array_column($baris, 'produk_id'));
            $total = 0;

            foreach ($baris as $b) {
                $p = $produk->get($b['produk_id'] ?? '') ?? throw new BillingException('Ada produk yang tidak ditemukan.');
                $qty = (int) ($b['qty'] ?? 0);
                $harga = (int) ($b['harga'] ?? 0);

                if ($qty < 1) {
                    throw new BillingException("Jumlah {$p->nama} minimal 1.");
                }

                if ($harga < 0) {
                    throw new BillingException("Harga pokok {$p->nama} tidak boleh negatif.");
                }

                $this->stok->catat(
                    $p,
                    $cabang->id,
                    $qty,
                    'masuk',
                    $user,
                    null,
                    trim("Stok masuk {$nomor} ".($keterangan ?? '')),
                    $harga
                );

                $total += $qty * $harga;
            }

            // Belanja dibayar dari laci kas -> tercatat di Pengeluaran (kategori stok, bukan beban) dan kas shift
            if ($dariKas && $total > 0) {
                $this->pengeluaran->catat(
                    $cabang,
                    $user,
                    [
                        'kategori' => 'stok',
                        'jumlah' => $total,
                        'keterangan' => "Belanja stok {$nomor}".($keterangan ? ": {$keterangan}" : ''),
                        'sumber_dana' => 'kas_laci',
                    ],
                    cekPlafon: false
                );
            }

            return $nomor;
        });
    }

    /**
     * @param  array<string,int|string|null>  $fisik  [produk_id => qty hitung fisik]
     * @return array{nomor:string, jumlah:int}
     */
    public function opname(Cabang $cabang, User $user, array $fisik, string $alasan): array
    {
        $alasan = trim($alasan);

        if (mb_strlen($alasan) < 3) {
            throw new BillingException('Keterangan opname wajib diisi.');
        }

        $fisik = array_filter($fisik, fn ($v) => $v !== null && $v !== '');

        if ($fisik === []) {
            throw new BillingException('Isi minimal satu stok fisik.');
        }

        return DB::transaction(function () use ($cabang, $user, $fisik, $alasan) {
            $nomor = $this->nomor->buat('OPN', $cabang);
            $produk = $this->produk($cabang, array_keys($fisik));
            $berubah = 0;

            foreach ($fisik as $produkId => $qtyFisik) {
                $p = $produk->get($produkId) ?? throw new BillingException('Ada produk yang tidak ditemukan.');
                $qtyFisik = (int) $qtyFisik;

                if ($qtyFisik < 0) {
                    throw new BillingException("Stok fisik {$p->nama} tidak boleh negatif.");
                }

                $saldo = $this->stok->kunci($p, $cabang->id);
                $selisih = $qtyFisik - $saldo->qty;

                if ($selisih === 0) {
                    continue;
                }

                $this->stok->catat($p, $cabang->id, $selisih, 'opname', $user, null, "Opname {$nomor}: {$alasan}");
                $berubah++;
            }

            return ['nomor' => $nomor, 'jumlah' => $berubah];
        });
    }

    private function produk(Cabang $cabang, array $ids): Collection
    {
        return Produk::withoutGlobalScopes()
            ->where('tenant_id', $cabang->tenant_id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }
}
