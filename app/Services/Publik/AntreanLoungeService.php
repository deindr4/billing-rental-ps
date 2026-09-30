<?php

namespace App\Services\Publik;

use App\Exceptions\BillingException;
use App\Models\AntreanLounge;
use App\Models\Cabang;
use App\Models\Member;
use App\Models\Sesi;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Antrean pelanggan di lounge saat semua unit terisi. Panggilan tampil di billboard + suara.
 */
final class AntreanLoungeService
{
    public function tambah(Cabang $cabang, User $user, array $data): AntreanLounge
    {
        $nama = trim((string) ($data['nama'] ?? ''));

        if (mb_strlen($nama) < 2) {
            throw new BillingException('Nama wajib diisi.');
        }

        return DB::transaction(function () use ($cabang, $user, $data, $nama) {
            $nomor = (int) AntreanLounge::withoutGlobalScopes()
                ->where('cabang_id', $cabang->id)
                ->whereDate('tanggal', today())
                ->lockForUpdate()
                ->max('nomor') + 1;

            $telepon = trim((string) ($data['telepon'] ?? ''));

            return AntreanLounge::create([
                'tenant_id' => $cabang->tenant_id,
                'cabang_id' => $cabang->id,
                'tanggal' => today()->toDateString(),
                'nomor' => $nomor,
                'nama' => $nama,
                'telepon' => $telepon !== '' ? Member::normalisasiTelepon($telepon) : null,
                'member_id' => ($data['member_id'] ?? null) ?: null,
                'tipe_konsol_id' => ($data['tipe_konsol_id'] ?? null) ?: null,
                'jumlah_orang' => max(1, min(8, (int) ($data['jumlah_orang'] ?? 1))),
                'catatan' => trim((string) ($data['catatan'] ?? '')) ?: null,
                'status' => AntreanLounge::STATUS_MENUNGGU,
                'user_id' => $user->id,
            ]);
        });
    }

    /** Panggil ke unit tertentu (tampil besar di billboard + suara). Boleh dipanggil ulang. */
    public function panggil(AntreanLounge $a, ?string $unitId): AntreanLounge
    {
        if (! in_array($a->status, [AntreanLounge::STATUS_MENUNGGU, AntreanLounge::STATUS_DIPANGGIL], true)) {
            throw new BillingException('Antrean ini sudah selesai.');
        }

        $unit = $unitId ? Unit::find($unitId) : $a->unit;

        $a->update([
            'status' => AntreanLounge::STATUS_DIPANGGIL,
            'unit_id' => $unit?->id,
            'dipanggil_pada' => now(),
            'jumlah_panggil' => $a->jumlah_panggil + 1,
        ]);

        return $a;
    }

    public function selesai(AntreanLounge $a, string $status): AntreanLounge
    {
        if (! in_array($status, [AntreanLounge::STATUS_DILAYANI, AntreanLounge::STATUS_BATAL], true)) {
            throw new BillingException('Status tidak valid.');
        }

        $a->update(['status' => $status]);

        return $a;
    }

    /** Unit kosong yang cocok (tipe konsol yang diminta dulu) */
    public function unitKosong(?string $tipeKonsolId = null): Collection
    {
        return Unit::query()->aktif()->where('status', Unit::STATUS_KOSONG)
            ->orderByRaw('tipe_konsol_id = ? desc', [$tipeKonsolId ?? ''])
            ->urut()
            ->get();
    }

    /**
     * Perkiraan menit tunggu per antrean: urutan ke-n mendapat sesi paket ke-n yang paling cepat selesai
     * (tipe konsol sama jika diminta). null = tidak bisa diperkirakan (hanya ada open billing).
     *
     * @return array<string, int|null> antrean_id => menit
     */
    public function perkiraanTunggu(Collection $antrean): array
    {
        $kosong = Unit::query()->aktif()->where('status', Unit::STATUS_KOSONG)->get(['id', 'tipe_konsol_id']);
        $selesai = Sesi::query()->aktif()->whereNotNull('berakhir_pada')->with('unit:id,tipe_konsol_id')
            ->orderBy('berakhir_pada')->get();

        $terpakai = [];
        $hasil = [];

        foreach ($antrean->where('status', AntreanLounge::STATUS_MENUNGGU) as $a) {
            $cocok = fn ($tipe) => ! $a->tipe_konsol_id || $tipe === $a->tipe_konsol_id;

            $unitBebas = $kosong->first(fn ($u) => $cocok($u->tipe_konsol_id) && ! isset($terpakai['u'.$u->id]));

            if ($unitBebas) {
                $terpakai['u'.$unitBebas->id] = true;
                $hasil[$a->id] = 0;

                continue;
            }

            $sesi = $selesai->first(fn ($s) => $cocok($s->unit?->tipe_konsol_id) && ! isset($terpakai['s'.$s->id]));

            if ($sesi) {
                $terpakai['s'.$sesi->id] = true;
                $hasil[$a->id] = max(0, (int) ceil(now()->diffInMinutes($sesi->berakhir_pada, false)));
            } else {
                $hasil[$a->id] = null;
            }
        }

        return $hasil;
    }
}
