<?php

namespace App\Services\Billing;

use App\Exceptions\BillingException;
use App\Models\Aksesori;
use App\Models\Pengaturan;
use App\Models\Sesi;
use App\Models\SesiAksesori;
use App\Models\SesiLog;
use App\Models\Transaksi;
use App\Models\TransaksiItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sewa aksesori di lokasi (stik tambahan, headset, setir) yang ditagih ke sesi unit.
 * - Flat per sesi: langsung masuk tagihan saat disewa.
 * - Per jam: dihitung saat dikembalikan / sesi selesai, dengan aturan pembulatan open billing
 *   (blok, toleransi, minimal); disewa sejak awal sesi = mengikuti lama main (tanpa pause & bonus).
 * Stok tersedia = stok - yang belum dikembalikan; dikembalikan otomatis saat sesi selesai / batal.
 */
final class AksesoriService
{
    /** Batal (salah input) tanpa biaya, hanya dalam rentang ini setelah disewa */
    public const BATAS_BATAL_MENIT = 5;

    public function __construct(private KalkulatorOpenBilling $kalkulator) {}

    public function sewa(Sesi $sesi, Aksesori $aksesori, int $qty, User $user): SesiAksesori
    {
        if ($qty < 1 || $qty > 20) {
            throw new BillingException('Jumlah aksesori tidak valid.');
        }

        return DB::transaction(function () use ($sesi, $aksesori, $qty, $user) {
            $sesi = Sesi::withoutGlobalScopes()->whereKey($sesi->id)->lockForUpdate()->firstOrFail();
            $aksesori = Aksesori::withoutGlobalScopes()->whereKey($aksesori->id)->lockForUpdate()->firstOrFail();

            if (! $sesi->isAktif()) {
                throw new BillingException('Sesi sudah selesai.');
            }

            if (! $aksesori->is_active || $aksesori->cabang_id !== $sesi->cabang_id) {
                throw new BillingException("Aksesori {$aksesori->nama} tidak tersedia di cabang ini.");
            }

            if ($aksesori->tersedia() < $qty) {
                throw new BillingException("{$aksesori->nama} tersisa {$aksesori->tersedia()} (sedang disewa unit lain).");
            }

            $perJam = $aksesori->satuan === Aksesori::SATUAN_JAM;
            $harga = 'Rp'.number_format($aksesori->harga, 0, ',', '.');
            $item = TransaksiItem::create([
                'tenant_id' => $sesi->tenant_id,
                'cabang_id' => $sesi->cabang_id,
                'transaksi_id' => $sesi->transaksi_id,
                'jenis' => TransaksiItem::JENIS_AKSESORI,
                'nama' => "Sewa {$aksesori->nama}".($perJam ? " ({$harga}/jam)" : ''),
                'qty' => $qty,
                'harga_satuan' => $perJam ? 0 : $aksesori->harga,
                'subtotal' => $perJam ? 0 : $aksesori->harga * $qty,
                'catatan' => $perJam ? 'Dihitung saat dikembalikan' : null,
            ]);

            $sewa = SesiAksesori::create([
                'tenant_id' => $sesi->tenant_id,
                'cabang_id' => $sesi->cabang_id,
                'sesi_id' => $sesi->id,
                'aksesori_id' => $aksesori->id,
                'transaksi_item_id' => $item->id,
                'user_id' => $user->id,
                'qty' => $qty,
                'harga' => $aksesori->harga,
                'satuan' => $aksesori->satuan,
                'mulai_pada' => now(),
            ]);

            $item->update(['referensi_type' => $sewa->getMorphClass(), 'referensi_id' => $sewa->id]);
            $this->segarkanTagihan($sesi);
            $this->log($sesi, 'aksesori', ['aksesori' => $aksesori->nama, 'qty' => $qty, 'satuan' => $aksesori->satuan], $user);

            return $sewa;
        });
    }

    /** Aksesori dikembalikan sebelum sesi selesai: biaya per jam dihitung sampai sekarang */
    public function kembalikan(SesiAksesori $sewa, ?User $user = null, ?Carbon $waktu = null): SesiAksesori
    {
        return DB::transaction(function () use ($sewa, $user, $waktu) {
            $sewa = SesiAksesori::withoutGlobalScopes()->whereKey($sewa->id)->lockForUpdate()->firstOrFail();

            if ($sewa->selesai_pada) {
                return $sewa;
            }

            $sesi = Sesi::withoutGlobalScopes()->findOrFail($sewa->sesi_id);
            $sewa->update(['selesai_pada' => $waktu ?? now()]);

            if ($sewa->perJam()) {
                $this->tagihPerJam($sewa, $sesi);
            }

            $this->segarkanTagihan($sesi);

            if ($user) {
                $this->log($sesi, 'aksesori_kembali', ['aksesori' => $sewa->aksesori?->nama, 'qty' => $sewa->qty], $user);
            }

            return $sewa;
        });
    }

    /** Salah input: dibatalkan tanpa biaya, maksimal BATAS_BATAL_MENIT setelah disewa */
    public function batal(SesiAksesori $sewa, User $user): void
    {
        DB::transaction(function () use ($sewa, $user) {
            $sewa = SesiAksesori::withoutGlobalScopes()->whereKey($sewa->id)->lockForUpdate()->firstOrFail();

            if ($sewa->selesai_pada) {
                throw new BillingException('Aksesori sudah dikembalikan.');
            }

            if ($sewa->mulai_pada->lt(now()->subMinutes(self::BATAS_BATAL_MENIT))) {
                throw new BillingException('Batal hanya bisa '.self::BATAS_BATAL_MENIT.' menit setelah disewa. Gunakan Kembalikan.');
            }

            $sesi = Sesi::withoutGlobalScopes()->findOrFail($sewa->sesi_id);
            $nama = $sewa->aksesori?->nama ?? 'Aksesori';
            $sewa->update(['selesai_pada' => now(), 'dibatalkan' => true]);

            TransaksiItem::withoutGlobalScopes()->whereKey($sewa->transaksi_item_id)->update([
                'nama' => "Sewa {$nama} (dibatalkan)", 'qty' => 0, 'harga_satuan' => 0, 'subtotal' => 0, 'catatan' => null,
            ]);

            $this->segarkanTagihan($sesi);
            Audit::catat('batal_aksesori', "Batal sewa {$nama} ×{$sewa->qty} (salah input)", $sesi, ['qty' => $sewa->qty], userId: $user->id);
        });
    }

    /** Sesi selesai: semua aksesori yang masih dipakai dikembalikan pada jam selesai sesi */
    public function akhiriSesi(Sesi $sesi): void
    {
        foreach (SesiAksesori::withoutGlobalScopes()->where('sesi_id', $sesi->id)->dipakai()->get() as $sewa) {
            $sewa->update(['selesai_pada' => $sesi->selesai_pada ?? now()]);

            if ($sewa->perJam()) {
                $this->tagihPerJam($sewa, $sesi);
            }
        }
    }

    /** Sesi / transaksi dibatalkan: aksesori kembali tersedia (tagihannya ikut batal bersama transaksi) */
    public function lepasSemua(Sesi $sesi): void
    {
        SesiAksesori::withoutGlobalScopes()->where('sesi_id', $sesi->id)->dipakai()->update(['selesai_pada' => now()]);
    }

    /** Aksesori sesi (untuk kartu unit, kelola sesi & pengingat pengembalian) */
    public function untukSesi(string $sesiId): Collection
    {
        return SesiAksesori::withoutGlobalScopes()->with('aksesori:id,nama')
            ->where('sesi_id', $sesiId)->where('dibatalkan', false)->orderBy('mulai_pada')->get();
    }

    /** Perkiraan biaya per jam yang sedang berjalan */
    public function perkiraan(SesiAksesori $sewa, Sesi $sesi): int
    {
        return $sewa->perJam() && ! $sewa->selesai_pada ? $this->hitungPerJam($sewa, $sesi) : 0;
    }

    private function tagihPerJam(SesiAksesori $sewa, Sesi $sesi): void
    {
        $biaya = $this->hitungPerJam($sewa, $sesi);
        $menit = intdiv($this->detikSewa($sewa, $sesi) + 59, 60);

        TransaksiItem::withoutGlobalScopes()->whereKey($sewa->transaksi_item_id)->update([
            'harga_satuan' => intdiv($biaya, max(1, $sewa->qty)),
            'subtotal' => $biaya,
            'catatan' => "Disewa {$menit} menit",
        ]);
    }

    private function hitungPerJam(SesiAksesori $sewa, Sesi $sesi): int
    {
        return $this->kalkulator->hitung(
            $sewa->harga * $sewa->qty,
            $this->detikSewa($sewa, $sesi),
            (int) Pengaturan::ambil('open_billing.blok_menit', 15, $sesi->cabang_id),
            (int) Pengaturan::ambil('open_billing.toleransi_menit', 5, $sesi->cabang_id),
            (int) Pengaturan::ambil('open_billing.minimal_menit', 60, $sesi->cabang_id),
            (int) Pengaturan::ambil('open_billing.pembulatan_rupiah', 0, $sesi->cabang_id),
        )['biaya'];
    }

    /** Disewa sejak awal sesi = lama main sesi (tanpa pause & bonus); disewa di tengah = sejak disewa */
    private function detikSewa(SesiAksesori $sewa, Sesi $sesi): int
    {
        $akhir = $sewa->selesai_pada ?? now();

        if ($sewa->mulai_pada->lte($sesi->mulai_pada) && (! $sesi->selesai_pada || $akhir->gte($sesi->selesai_pada))) {
            return $sesi->durasiBerjalanDetik();
        }

        return (int) max(0, $sewa->mulai_pada->diffInSeconds($akhir));
    }

    private function segarkanTagihan(Sesi $sesi): void
    {
        Transaksi::withoutGlobalScopes()->findOrFail($sesi->transaksi_id)->hitungUlang();
        Sesi::withoutGlobalScopes()->whereKey($sesi->id)->increment('versi_tagihan');
    }

    private function log(Sesi $sesi, string $jenis, array $data, User $user): void
    {
        SesiLog::create([
            'tenant_id' => $sesi->tenant_id, 'cabang_id' => $sesi->cabang_id, 'sesi_id' => $sesi->id,
            'user_id' => $user->id, 'jenis' => $jenis, 'data' => $data,
        ]);
    }
}
