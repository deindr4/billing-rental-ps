<?php

namespace App\Services\Aset;

use App\Exceptions\BillingException;
use App\Models\Aset;
use App\Models\Cabang;
use App\Models\Maintenance;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\PengeluaranService;
use Illuminate\Support\Facades\DB;

/**
 * Tiket maintenance: dijadwalkan -> dikerjakan -> selesai (atau batal).
 * Saat dikerjakan, unit ikut berstatus servis (tidak bisa disewa, TV menampilkan layar servis).
 */
final class MaintenanceService
{
    public function __construct(private PengeluaranService $pengeluaran) {}

    /** @param array{jenis:string, judul:string, deskripsi?:?string, aset_id?:?string, unit_id?:?string, dijadwalkan_pada?:?string, vendor?:?string, mulai?:bool} $data */
    public function buat(Cabang $cabang, User $user, array $data): Maintenance
    {
        if (! array_key_exists($data['jenis'] ?? '', Maintenance::JENIS)) {
            throw new BillingException('Jenis maintenance tidak valid.');
        }

        if (mb_strlen(trim($data['judul'] ?? '')) < 3) {
            throw new BillingException('Judul wajib diisi.');
        }

        $aset = ! empty($data['aset_id']) ? Aset::findOrFail($data['aset_id']) : null;
        $unitId = ($data['unit_id'] ?? null) ?: $aset?->unit_id;

        if (! $aset && ! $unitId) {
            throw new BillingException('Pilih unit atau aset.');
        }

        return DB::transaction(function () use ($cabang, $user, $data, $aset, $unitId) {
            $m = Maintenance::create([
                'tenant_id' => $cabang->tenant_id,
                'cabang_id' => $cabang->id,
                'aset_id' => $aset?->id,
                'unit_id' => $unitId,
                'jenis' => $data['jenis'],
                'judul' => trim($data['judul']),
                'deskripsi' => trim((string) ($data['deskripsi'] ?? '')) ?: null,
                'vendor' => trim((string) ($data['vendor'] ?? '')) ?: null,
                'dijadwalkan_pada' => ($data['dijadwalkan_pada'] ?? null) ?: null,
                'status' => 'dijadwalkan',
                'user_id' => $user->id,
            ]);

            if (! empty($data['mulai'])) {
                $this->mulai($m, $user);
            }

            return $m->refresh();
        });
    }

    /** Mulai dikerjakan: unit & aset jadi status servis */
    public function mulai(Maintenance $m, User $user): Maintenance
    {
        return DB::transaction(function () use ($m) {
            $m = Maintenance::whereKey($m->id)->lockForUpdate()->firstOrFail();

            if ($m->status !== 'dijadwalkan') {
                throw new BillingException('Maintenance ini tidak bisa dimulai.');
            }

            if ($m->unit_id) {
                $unit = Unit::whereKey($m->unit_id)->lockForUpdate()->firstOrFail();

                if (! in_array($unit->status, [Unit::STATUS_KOSONG, Unit::STATUS_SERVIS], true)) {
                    throw new BillingException("{$unit->nama} sedang dipakai. Selesaikan sesi dulu sebelum servis.");
                }

                $unit->update(['status' => Unit::STATUS_SERVIS]);
            }

            if ($m->aset_id) {
                Aset::whereKey($m->aset_id)->whereIn('status', ['aktif', 'rusak'])->update(['status' => 'servis']);
            }

            $m->update(['status' => 'dikerjakan', 'mulai_pada' => now()]);

            return $m;
        });
    }

    /**
     * Selesai. Biaya bisa dicatat sebagai pengeluaran (kategori Sparepart & Perbaikan).
     *
     * @param  array{hasil?:?string, biaya?:int, vendor?:?string, catat_pengeluaran?:bool, sumber_dana?:string, aset_rusak?:bool}  $data
     */
    public function selesai(Maintenance $m, User $user, array $data = []): Maintenance
    {
        return DB::transaction(function () use ($m, $user, $data) {
            $m = Maintenance::whereKey($m->id)->lockForUpdate()->firstOrFail();

            if (! $m->isTerbuka()) {
                throw new BillingException('Maintenance sudah ditutup.');
            }

            $biaya = max(0, (int) ($data['biaya'] ?? 0));
            $pengeluaranId = null;

            if ($biaya > 0 && ! empty($data['catat_pengeluaran'])) {
                $p = $this->pengeluaran->catat(
                    Cabang::findOrFail($m->cabang_id),
                    $user,
                    [
                        'jumlah' => $biaya,
                        'kategori' => 'sparepart',
                        'sumber_dana' => $data['sumber_dana'] ?? 'rekening',
                        'keterangan' => 'Maintenance: '.$m->judul,
                    ],
                );
                $pengeluaranId = $p->id;
            }

            $m->update([
                'status' => 'selesai',
                'mulai_pada' => $m->mulai_pada ?? now(),
                'selesai_pada' => now(),
                'biaya' => $biaya,
                'vendor' => trim((string) ($data['vendor'] ?? $m->vendor)) ?: null,
                'hasil' => trim((string) ($data['hasil'] ?? '')) ?: null,
                'pengeluaran_id' => $pengeluaranId,
                'diselesaikan_oleh' => $user->id,
            ]);

            $this->pulihkan($m, ! empty($data['aset_rusak']));

            if ($m->aset_id) {
                Aset::whereKey($m->aset_id)->update(['servis_terakhir' => now()->toDateString()]);
            }

            return $m;
        });
    }

    public function batal(Maintenance $m, User $user, string $alasan): Maintenance
    {
        return DB::transaction(function () use ($m, $alasan) {
            $m = Maintenance::whereKey($m->id)->lockForUpdate()->firstOrFail();

            if (! $m->isTerbuka()) {
                throw new BillingException('Maintenance sudah ditutup.');
            }

            $m->update(['status' => 'batal', 'hasil' => 'Dibatalkan: '.trim($alasan)]);
            $this->pulihkan($m, false);

            return $m;
        });
    }

    /** Kembalikan status unit & aset jika tidak ada maintenance lain yang masih dikerjakan */
    private function pulihkan(Maintenance $m, bool $asetRusak): void
    {
        if ($m->unit_id) {
            $masihAda = Maintenance::query()->where('unit_id', $m->unit_id)->where('status', 'dikerjakan')->whereKeyNot($m->id)->exists();

            $unit = $masihAda ? null : Unit::find($m->unit_id);

            // Lewat model supaya observer menyegarkan layar TV
            if ($unit && $unit->status === Unit::STATUS_SERVIS) {
                $unit->update(['status' => Unit::STATUS_KOSONG]);
            }
        }

        if ($m->aset_id) {
            $masihAda = Maintenance::query()->where('aset_id', $m->aset_id)->where('status', 'dikerjakan')->whereKeyNot($m->id)->exists();

            if ($asetRusak) {
                Aset::whereKey($m->aset_id)->where('status', '!=', 'dilepas')->update(['status' => 'rusak']);
            } elseif (! $masihAda) {
                Aset::whereKey($m->aset_id)->where('status', 'servis')->update(['status' => 'aktif']);
            }
        }
    }
}
