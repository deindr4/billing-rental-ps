<?php

namespace App\Services\Billing;

use App\Models\Produk;
use App\Models\ProdukStok;
use App\Models\StokMutasi;
use App\Models\User;
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
