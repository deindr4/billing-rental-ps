<?php

namespace App\Services\Aset;

use App\Exceptions\BillingException;
use App\Models\Cabang;
use App\Models\ModalMutasi;
use App\Models\User;
use App\Services\Billing\KasService;
use App\Services\Billing\ShiftService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Arus modal owner: suntikan modal & prive. Jika lewat kas laci, ikut tercatat di kas shift.
 */
final class ModalService
{
    public function __construct(private ShiftService $shift, private KasService $kas) {}

    public function catat(Cabang $cabang, User $user, string $jenis, int $jumlah, string $sumber, string $tanggal, string $keterangan): ModalMutasi
    {
        if (! array_key_exists($jenis, ModalMutasi::JENIS)) {
            throw new BillingException('Jenis tidak valid.');
        }

        if (! array_key_exists($sumber, ModalMutasi::SUMBER)) {
            throw new BillingException('Sumber dana tidak valid.');
        }

        if ($jumlah <= 0) {
            throw new BillingException('Nominal harus lebih dari 0.');
        }

        if (mb_strlen(trim($keterangan)) < 3) {
            throw new BillingException('Keterangan wajib diisi.');
        }

        return DB::transaction(function () use ($cabang, $user, $jenis, $jumlah, $sumber, $tanggal, $keterangan) {
            $shift = null;

            if ($sumber === 'kas_laci') {
                $shift = $this->shift->wajibAktif($user, $cabang->id);

                if ($jenis === 'prive' && $this->kas->saldo($shift) < $jumlah) {
                    throw new BillingException('Uang di laci kas tidak cukup.');
                }

                $tanggal = now()->toDateString(); // uang laci = hari ini
            }

            $m = ModalMutasi::create([
                'tenant_id' => $cabang->tenant_id,
                'cabang_id' => $cabang->id,
                'jenis' => $jenis,
                'jumlah' => $jumlah,
                'tanggal' => $tanggal,
                'sumber' => $sumber,
                'keterangan' => trim($keterangan),
                'shift_id' => $shift?->id,
                'user_id' => $user->id,
                'status' => 'aktif',
            ]);

            Audit::catat($jenis, ModalMutasi::JENIS[$jenis].' Rp '.number_format($jumlah, 0, ',', '.').': '.trim($keterangan), $m, ['sumber' => $sumber], anomali: false, userId: $user->id);

            if ($shift) {
                $this->kas->catat($shift, $jenis, $jenis === 'modal' ? $jumlah : -$jumlah, $user, $m, ModalMutasi::JENIS[$jenis].': '.trim($keterangan));
            }

            return $m;
        });
    }

    public function batalkan(ModalMutasi $m, User $user): ModalMutasi
    {
        return DB::transaction(function () use ($m, $user) {
            $m = ModalMutasi::whereKey($m->id)->lockForUpdate()->firstOrFail();

            if ($m->status !== 'aktif') {
                throw new BillingException('Sudah dibatalkan.');
            }

            if ($m->sumber === 'kas_laci') {
                $shift = $this->shift->wajibAktif($user, $m->cabang_id);

                if ($m->jenis === 'modal' && $this->kas->saldo($shift) < $m->jumlah) {
                    throw new BillingException('Uang di laci kas tidak cukup untuk membatalkan.');
                }

                $this->kas->catat($shift, $m->jenis, $m->jenis === 'modal' ? -$m->jumlah : $m->jumlah, $user, $m, 'Batal '.mb_strtolower(ModalMutasi::JENIS[$m->jenis]));
            }

            $m->update(['status' => 'dibatalkan']);

            return $m;
        });
    }
}
