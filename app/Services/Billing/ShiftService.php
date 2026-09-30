<?php

namespace App\Services\Billing;

use App\Exceptions\BillingException;
use App\Models\Cabang;
use App\Models\Shift;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

final class ShiftService
{
    public function __construct(
        private NomorTransaksi $nomor,
        private KasService $kas,
    ) {}

    public function aktif(User $user, string $cabangId): ?Shift
    {
        return Shift::withoutGlobalScopes()
            ->where('cabang_id', $cabangId)
            ->where('user_id', $user->id)
            ->where('status', Shift::STATUS_BUKA)
            ->latest('dibuka_pada')
            ->first();
    }

    public function wajibAktif(User $user, string $cabangId): Shift
    {
        return $this->aktif($user, $cabangId)
            ?? throw new BillingException('Buka shift dan isi kas awal terlebih dahulu.');
    }

    public function buka(User $user, Cabang $cabang, int $kasAwal): Shift
    {
        if ($kasAwal < 0) {
            throw new BillingException('Kas awal tidak boleh negatif.');
        }

        return DB::transaction(function () use ($user, $cabang, $kasAwal) {
            if ($this->aktif($user, $cabang->id)) {
                throw new BillingException('Masih ada shift yang terbuka. Tutup shift sebelumnya dulu.');
            }

            $shift = Shift::create([
                'tenant_id' => $cabang->tenant_id,
                'cabang_id' => $cabang->id,
                'user_id' => $user->id,
                'nomor' => $this->nomor->buat('SFT', $cabang),
                'status' => Shift::STATUS_BUKA,
                'dibuka_pada' => now(),
                'kas_awal' => $kasAwal,
            ]);

            $this->kas->catat($shift, 'kas_awal', $kasAwal, $user, $shift, 'Kas awal shift');

            return $shift;
        });
    }

    /**
     * @param  array<string,int>|null  $pecahan  contoh ['100000' => 2, '50000' => 1]
     */
    public function tutup(Shift $shift, User $user, int $kasFisik, ?string $catatan = null, ?array $pecahan = null): Shift
    {
        return DB::transaction(function () use ($shift, $user, $kasFisik, $catatan, $pecahan) {
            $shift = Shift::withoutGlobalScopes()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if (! $shift->isBuka()) {
                throw new BillingException('Shift ini sudah ditutup.');
            }

            if ($kasFisik < 0) {
                throw new BillingException('Kas fisik tidak boleh negatif.');
            }

            $seharusnya = $this->kas->saldo($shift);
            $selisih = $kasFisik - $seharusnya;

            if ($selisih !== 0 && blank($catatan)) {
                throw new BillingException('Ada selisih kas. Isi keterangan terlebih dahulu.');
            }

            $shift->update([
                'status' => Shift::STATUS_TUTUP,
                'ditutup_pada' => now(),
                'kas_seharusnya' => $seharusnya,
                'kas_fisik' => $kasFisik,
                'selisih' => $selisih,
                'rincian_pecahan' => $pecahan,
                'catatan_tutup' => $catatan,
                'ditutup_oleh' => $user->id,
            ]);

            if ($selisih !== 0) {
                Audit::catat('selisih_kas', "Selisih kas shift {$shift->nomor}: Rp ".number_format($selisih, 0, ',', '.')." ({$catatan})", $shift, [
                    'seharusnya' => $seharusnya, 'fisik' => $kasFisik, 'selisih' => $selisih,
                ], userId: $user->id);
            }

            return $shift;
        });
    }
}
