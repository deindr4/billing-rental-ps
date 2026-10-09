<?php

namespace App\Services\Karyawan;

use App\Exceptions\BillingException;
use App\Models\Cabang;
use App\Models\Karyawan;
use App\Models\Pembayaran;
use App\Models\Penggajian;
use App\Models\Shift;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\Billing\NomorTransaksi;
use App\Services\Billing\PengeluaranService;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rekap gaji: pokok bulanan (prorata bila periode bukan satu bulan penuh) + upah per hari hadir + upah per jam kerja
 * (dari absensi) + bonus target omzet per shift yang dipegang + penyesuaian manual − potongan selisih kas yang
 * DISETUJUI owner (usulan: kas kurang saat tutup / serah terima pada shift yang dipegang karyawan).
 */
final class GajiService
{
    public function __construct(
        private AbsensiService $absensi,
        private NomorTransaksi $nomor,
        private PengeluaranService $pengeluaran,
    ) {}

    /**
     * Periode bawaan sebelum tanggal acuan: bulanan = bulan lalu, mingguan = Senin–Minggu lalu, harian = kemarin.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function periodeBawaan(string $periode, ?CarbonInterface $acuan = null): array
    {
        $acuan = Carbon::parse($acuan ?? today());

        return match ($periode) {
            'mingguan' => [$acuan->copy()->subWeek()->startOfWeek(Carbon::MONDAY), $acuan->copy()->subWeek()->endOfWeek(Carbon::SUNDAY)->startOfDay()],
            'harian' => [$acuan->copy()->subDay(), $acuan->copy()->subDay()],
            default => [$acuan->copy()->subMonthNoOverflow()->startOfMonth(), $acuan->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
        };
    }

    /**
     * Hitung semua komponen. $setuju = kunci usulan potongan yang disetujui; $penyesuaian = [[keterangan, nilai ±]].
     */
    public function hitung(Karyawan $k, CarbonInterface $dari, CarbonInterface $sampai, array $setuju = [], array $penyesuaian = []): array
    {
        $dari = Carbon::parse($dari)->startOfDay();
        $sampai = Carbon::parse($sampai)->endOfDay();
        $hari = (int) $dari->diffInDays($sampai->copy()->startOfDay()) + 1;

        // Pokok bulanan: penuh bila periode = satu bulan kalender, selain itu prorata hari
        $sebulanPenuh = $dari->isSameDay($dari->copy()->startOfMonth()) && $sampai->isSameDay($dari->copy()->endOfMonth());
        $pokok = $k->gaji_pokok > 0 ? ($sebulanPenuh ? $k->gaji_pokok : (int) round($k->gaji_pokok * $hari / $dari->daysInMonth)) : 0;

        $hadir = $this->absensi->rekap($k, $dari, $sampai);
        $upahHadir = $k->upah_shift * $hadir['hari_hadir'];
        $upahJam = intdiv($k->upah_jam * $hadir['menit_kerja'], 60);

        $bonusShift = [];
        $usulan = [];

        if ($k->user_id) {
            $shifts = Shift::withoutGlobalScopes()->where('tenant_id', $k->tenant_id)->where('user_id', $k->user_id)
                ->whereBetween('dibuka_pada', [$dari, $sampai])->orderBy('dibuka_pada')->get();

            foreach ($shifts as $s) {
                $omzet = $this->omzetShift($s);

                if ($k->bonus_target > 0 && $omzet >= $k->bonus_target) {
                    $nilai = $k->bonus_jenis === 'persen'
                        ? intdiv(($omzet - $k->bonus_target) * $k->bonus_nilai, 100)
                        : $k->bonus_nilai;
                    $bonusShift[] = ['shift' => $s->nomor, 'tanggal' => $s->dibuka_pada->format('d/m'), 'omzet' => $omzet, 'bonus' => $nilai];
                }

                if ((int) $s->selisih < 0) {
                    $usulan[] = ['kunci' => 'kas-'.$s->id, 'shift' => $s->nomor, 'tanggal' => $s->dibuka_pada->format('d/m'),
                        'jenis' => 'Kas kurang saat tutup / serah terima', 'nilai' => -(int) $s->selisih];
                }

                // Uang yang ditinggal kurang saat dihitung penerima: tanggung jawab penyerah
                $berikut = $s->shift_berikut_id ? Shift::withoutGlobalScopes()->find($s->shift_berikut_id) : null;

                if ($berikut && (int) $berikut->selisih_terima < 0) {
                    $usulan[] = ['kunci' => 'terima-'.$berikut->id, 'shift' => $s->nomor, 'tanggal' => $s->dibuka_pada->format('d/m'),
                        'jenis' => 'Selisih serah terima (dihitung penerima)', 'nilai' => -(int) $berikut->selisih_terima];
                }
            }
        }

        $usulan = array_map(fn ($u) => $u + ['disetujui' => in_array($u['kunci'], $setuju, true)], $usulan);
        $penyesuaian = array_values(array_filter(array_map(fn ($p) => [
            'keterangan' => trim((string) ($p['keterangan'] ?? '')),
            'nilai' => (int) ($p['nilai'] ?? 0),
        ], $penyesuaian), fn ($p) => $p['keterangan'] !== '' && $p['nilai'] !== 0));

        $bonus = (int) array_sum(array_column($bonusShift, 'bonus'));
        $totalPenyesuaian = (int) array_sum(array_column($penyesuaian, 'nilai'));
        $potongan = (int) array_sum(array_map(fn ($u) => $u['disetujui'] ? $u['nilai'] : 0, $usulan));

        return [
            'gaji_pokok' => $pokok,
            'upah_hadir' => $upahHadir,
            'upah_jam' => $upahJam,
            'bonus' => $bonus,
            'penyesuaian' => $totalPenyesuaian,
            'potongan' => $potongan,
            'total' => max(0, $pokok + $upahHadir + $upahJam + $bonus + $totalPenyesuaian - $potongan),
            'rincian' => [
                'hari_periode' => $hari,
                'pokok_prorata' => ! $sebulanPenuh && $k->gaji_pokok > 0,
                'hadir' => $hadir,
                'tarif' => ['gaji_pokok' => $k->gaji_pokok, 'upah_shift' => $k->upah_shift, 'upah_jam' => $k->upah_jam,
                    'bonus_target' => $k->bonus_target, 'bonus_jenis' => $k->bonus_jenis, 'bonus_nilai' => $k->bonus_nilai],
                'bonus_shift' => $bonusShift,
                'usulan_potongan' => $usulan,
                'penyesuaian' => $penyesuaian,
            ],
        ];
    }

    /** Omzet yang diterima di shift (pembayaran sukses, tanpa top up saldo member) */
    public function omzetShift(Shift $s): int
    {
        return (int) Pembayaran::withoutGlobalScopes()
            ->where('pembayaran.shift_id', $s->id)->where('pembayaran.status', 'sukses')
            ->whereIn('pembayaran.transaksi_id', Transaksi::withoutGlobalScopes()->where('jenis', '!=', Transaksi::JENIS_TOP_UP)->select('id'))
            ->sum('pembayaran.jumlah');
    }

    public function buat(Karyawan $k, CarbonInterface $dari, CarbonInterface $sampai, User $user, Cabang $cabang): Penggajian
    {
        if (Carbon::parse($sampai)->lt(Carbon::parse($dari))) {
            throw new BillingException('Tanggal akhir periode sebelum tanggal mulai.');
        }

        $bentrok = Penggajian::query()->where('karyawan_id', $k->id)->where('status', '!=', 'batal')
            ->whereDate('periode_mulai', '<=', $sampai)->whereDate('periode_selesai', '>=', $dari)->first();

        if ($bentrok) {
            throw new BillingException("{$k->nama} sudah punya rekap {$bentrok->nomor} ({$bentrok->labelPeriode()}).");
        }

        return Penggajian::create([
            'tenant_id' => $k->tenant_id,
            'karyawan_id' => $k->id,
            'cabang_id' => $k->cabang_id ?? $cabang->id,
            'nomor' => $this->nomor->buat('GJI', $cabang),
            'periode_mulai' => Carbon::parse($dari)->toDateString(),
            'periode_selesai' => Carbon::parse($sampai)->toDateString(),
            'status' => 'draft',
            'dibuat_oleh' => $user->id,
        ] + $this->hitung($k, $dari, $sampai));
    }

    /** Draft: potongan yang disetujui & penyesuaian diubah owner → semua dihitung ulang dari data terbaru */
    public function hitungUlang(Penggajian $p, array $setuju, array $penyesuaian, ?string $catatan = null): Penggajian
    {
        $this->wajibDraft($p);
        $p->update($this->hitung($p->karyawan, $p->periode_mulai, $p->periode_selesai, $setuju, $penyesuaian) + ['catatan' => $catatan]);

        return $p;
    }

    public function setujui(Penggajian $p, User $user): Penggajian
    {
        $this->wajibDraft($p);
        $p->update(['status' => 'disetujui', 'disetujui_oleh' => $user->id, 'disetujui_pada' => now()]);

        return $p;
    }

    /** Dibayar: dicatat sebagai pengeluaran kategori gaji (kas laci butuh shift terbuka & saldo cukup) */
    public function bayar(Penggajian $p, User $user, string $sumberDana, Cabang $cabang): Penggajian
    {
        if ($p->status !== 'disetujui') {
            throw new BillingException('Rekap gaji harus disetujui dulu.');
        }

        if ($p->total <= 0) {
            throw new BillingException('Total gaji Rp0, tidak ada yang dibayar.');
        }

        return DB::transaction(function () use ($p, $user, $sumberDana, $cabang) {
            $pengeluaran = $this->pengeluaran->catat($cabang, $user, [
                'kategori' => 'gaji',
                'jumlah' => $p->total,
                'keterangan' => "Gaji {$p->karyawan->nama} {$p->labelPeriode()} ({$p->nomor})",
                'sumber_dana' => $sumberDana,
            ], cekPlafon: false);

            $p->update(['status' => 'dibayar', 'dibayar_pada' => now(), 'sumber_dana' => $sumberDana, 'pengeluaran_id' => $pengeluaran->id]);

            return $p;
        });
    }

    /** Batal: draft/disetujui langsung batal; yang sudah dibayar ikut membatalkan pengeluarannya */
    public function batal(Penggajian $p, User $user, string $alasan): Penggajian
    {
        if (mb_strlen(trim($alasan)) < 5) {
            throw new BillingException('Alasan pembatalan minimal 5 karakter.');
        }

        return DB::transaction(function () use ($p, $user, $alasan) {
            if ($p->status === 'dibayar' && $p->pengeluaran) {
                $this->pengeluaran->batalkan($p->pengeluaran, $user, "Batal rekap gaji {$p->nomor}: {$alasan}");
            }

            $p->update(['status' => 'batal', 'catatan' => trim(($p->catatan ? $p->catatan."\n" : '').'Dibatalkan: '.$alasan)]);
            Audit::catat('batal_gaji', "Batal rekap gaji {$p->nomor} {$p->karyawan->nama}: {$alasan}", $p, userId: $user->id);

            return $p;
        });
    }

    private function wajibDraft(Penggajian $p): void
    {
        if (! $p->bisaDiubah()) {
            throw new BillingException('Rekap gaji sudah '.mb_strtolower(Penggajian::STATUS[$p->status] ?? $p->status).'.');
        }
    }
}
