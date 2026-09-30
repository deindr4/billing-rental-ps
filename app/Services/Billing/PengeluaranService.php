<?php

namespace App\Services\Billing;

use App\Exceptions\BillingException;
use App\Exceptions\PlafonTerlampaui;
use App\Models\Cabang;
use App\Models\Pengaturan;
use App\Models\Pengeluaran;
use App\Models\Shift;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

final class PengeluaranService
{
    public const PLAFON_DEFAULT = 500000;

    public function __construct(
        private NomorTransaksi $nomor,
        private ShiftService $shift,
        private KasService $kas,
    ) {}

    /** Plafon pengeluaran kas laci per shift (0 = tanpa batas) */
    public function plafonShift(string $cabangId): int
    {
        return (int) Pengaturan::ambil('pengeluaran.plafon_shift', self::PLAFON_DEFAULT, $cabangId);
    }

    public function terpakaiShift(Shift $shift): int
    {
        return (int) Pengeluaran::withoutGlobalScopes()
            ->where('shift_id', $shift->id)
            ->where('sumber_dana', 'kas_laci')
            ->where('status', 'aktif')
            ->sum('jumlah');
    }

    /**
     * @param  array{kategori:string, jumlah:int, keterangan:string, sumber_dana:string}  $data
     */
    public function catat(
        Cabang $cabang,
        User $user,
        array $data,
        ?string $fotoNota = null,
        ?User $penyetuju = null,
        bool $cekPlafon = true,
    ): Pengeluaran {
        $jumlah = (int) ($data['jumlah'] ?? 0);
        $keterangan = trim((string) ($data['keterangan'] ?? ''));
        $kategori = $data['kategori'] ?? '';
        $sumber = $data['sumber_dana'] ?? '';

        if (! array_key_exists($kategori, Pengeluaran::KATEGORI)) {
            throw new BillingException('Kategori pengeluaran tidak valid.');
        }

        if (! array_key_exists($sumber, Pengeluaran::SUMBER_DANA)) {
            throw new BillingException('Sumber dana tidak valid.');
        }

        if ($jumlah <= 0) {
            throw new BillingException('Nominal pengeluaran harus lebih dari 0.');
        }

        if (mb_strlen($keterangan) < 3) {
            throw new BillingException('Keterangan wajib diisi.');
        }

        return DB::transaction(function () use ($cabang, $user, $jumlah, $keterangan, $kategori, $sumber, $fotoNota, $penyetuju, $cekPlafon) {
            $shift = null;

            if ($sumber === 'kas_laci') {
                $shift = $this->shift->wajibAktif($user, $cabang->id);

                if ($this->kas->saldo($shift) < $jumlah) {
                    throw new BillingException('Uang di laci kas tidak cukup untuk pengeluaran ini.');
                }

                $plafon = $this->plafonShift($cabang->id);

                if ($cekPlafon && $plafon > 0 && ! $penyetuju && $this->terpakaiShift($shift) + $jumlah > $plafon) {
                    $sisa = max(0, $plafon - $this->terpakaiShift($shift));

                    throw new PlafonTerlampaui(sprintf(
                        'Melebihi plafon kas laci shift (sisa Rp %s). Butuh PIN persetujuan.',
                        number_format($sisa, 0, ',', '.')
                    ));
                }
            }

            $pengeluaran = Pengeluaran::create([
                'tenant_id' => $cabang->tenant_id,
                'cabang_id' => $cabang->id,
                'shift_id' => $shift?->id,
                'user_id' => $user->id,
                'disetujui_oleh' => $penyetuju?->id,
                'nomor' => $this->nomor->buat('PGL', $cabang),
                'kategori' => $kategori,
                'jumlah' => $jumlah,
                'keterangan' => $keterangan,
                'sumber_dana' => $sumber,
                'foto_nota' => $fotoNota,
                'status' => 'aktif',
            ]);

            if ($shift) {
                $this->kas->catat($shift, 'pengeluaran', -$jumlah, $user, $pengeluaran, "Pengeluaran {$pengeluaran->nomor}: {$keterangan}");
            }

            return $pengeluaran;
        });
    }

    public function batalkan(Pengeluaran $pengeluaran, User $user, string $alasan): Pengeluaran
    {
        if (mb_strlen(trim($alasan)) < 5) {
            throw new BillingException('Alasan pembatalan wajib diisi (minimal 5 karakter).');
        }

        return DB::transaction(function () use ($pengeluaran, $user, $alasan) {
            $pengeluaran = Pengeluaran::withoutGlobalScopes()->whereKey($pengeluaran->id)->lockForUpdate()->firstOrFail();

            if ($pengeluaran->isDibatalkan()) {
                throw new BillingException('Pengeluaran sudah dibatalkan.');
            }

            // Uang kembali ke laci kas shift yang membatalkan
            if ($pengeluaran->sumber_dana === 'kas_laci') {
                $shift = $this->shift->wajibAktif($user, $pengeluaran->cabang_id);
                $this->kas->catat($shift, 'pembatalan', $pengeluaran->jumlah, $user, $pengeluaran, "Batal pengeluaran {$pengeluaran->nomor}: {$alasan}");
            }

            $pengeluaran->update([
                'status' => 'dibatalkan',
                'dibatalkan_pada' => now(),
                'dibatalkan_oleh' => $user->id,
                'alasan_batal' => $alasan,
            ]);

            Audit::catat('batal_pengeluaran', "Batal pengeluaran {$pengeluaran->nomor} Rp ".number_format($pengeluaran->jumlah, 0, ',', '.').": {$alasan}", $pengeluaran, userId: $user->id);

            return $pengeluaran;
        });
    }
}
