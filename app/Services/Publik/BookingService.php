<?php

namespace App\Services\Publik;

use App\Exceptions\BillingException;
use App\Models\Booking;
use App\Models\Cabang;
use App\Models\Member;
use App\Models\Pengaturan;
use App\Models\Sesi;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Booking unit: dari portal publik (online) atau dicatat kasir.
 * Slot dihitung per 30 menit; unit dianggap sibuk jika ada booking aktif yang bertabrakan
 * atau sesi berjalan yang belum selesai pada jam itu.
 */
final class BookingService
{
    public const LANGKAH_MENIT = 30;

    /* ---------------- Pengaturan per cabang ---------------- */

    public function aturan(string $cabangId): array
    {
        $a = fn ($k, $d) => Pengaturan::ambil('booking.'.$k, $d, $cabangId);

        // Jam booking = jam operasional cabang (pengaturan lama booking.jam_* tetap dihormati)
        $jam = self::jamOperasionalCabang($cabangId);

        return [
            'aktif' => (bool) $a('aktif', false),
            'jam_buka' => $jam['buka'],
            'jam_tutup' => $jam['tutup'],
            'min_menit_sebelum' => max(0, (int) $a('min_menit_sebelum', 60)),
            'maks_hari' => max(1, min(30, (int) $a('maks_hari', 7))),
            'durasi' => array_values(array_filter(array_map('intval', (array) $a('durasi', [60, 120, 180])))) ?: [60, 120, 180],
            'konfirmasi_otomatis' => (bool) $a('konfirmasi_otomatis', false),
            'toleransi_menit' => max(0, (int) $a('toleransi_menit', 15)),
            'jeda_menit' => max(0, (int) $a('jeda_menit', 10)),
            'maks_aktif_per_hp' => max(1, (int) $a('maks_aktif_per_hp', 2)),
        ];
    }

    /** Jam operasional cabang: ['buka' => '10:00', 'tutup' => '23:00', 'keterangan' => 'Setiap hari'] */
    public static function jamOperasionalCabang(string $cabangId): array
    {
        return [
            'buka' => (string) (Pengaturan::ambil('operasional.jam_buka', null, $cabangId) ?? Pengaturan::ambil('booking.jam_buka', '10:00', $cabangId)),
            'tutup' => (string) (Pengaturan::ambil('operasional.jam_tutup', null, $cabangId) ?? Pengaturan::ambil('booking.jam_tutup', '23:00', $cabangId)),
            'keterangan' => trim((string) Pengaturan::ambil('operasional.keterangan', 'Setiap hari', $cabangId)),
        ];
    }

    /* ---------------- Ketersediaan ---------------- */

    /**
     * Slot jam mulai pada tanggal tertentu + unit yang kosong di tiap slot.
     *
     * @return array<int, array{jam:string, mulai:Carbon, unit:Collection}>
     */
    public function slot(Cabang $cabang, CarbonInterface $tanggal, int $durasi, ?string $tipeKonsolId = null, bool $untukKasir = false): array
    {
        $a = $this->aturan($cabang->id);
        [$buka, $tutup] = $this->jamOperasional($tanggal, $a);

        $units = Unit::withoutGlobalScopes()->where('cabang_id', $cabang->id)->where('is_active', true)
            ->where('status', '!=', Unit::STATUS_SERVIS)
            ->when($tipeKonsolId, fn ($q) => $q->where('tipe_konsol_id', $tipeKonsolId))
            ->orderBy('urutan')->get();

        $booking = Booking::withoutGlobalScopes()->where('cabang_id', $cabang->id)->aktif()
            ->where('mulai_pada', '<', $tutup->copy()->addHours(12))->where('selesai_pada', '>', $buka)
            ->get(['unit_id', 'mulai_pada', 'selesai_pada']);

        $sibukSesi = $this->sibukSesi($cabang);
        $paling_awal = now()->addMinutes($untukKasir ? 0 : $a['min_menit_sebelum']);
        $jeda = $a['jeda_menit'];

        $hasil = [];

        for ($mulai = $buka->copy(); $mulai->copy()->addMinutes($durasi)->lte($tutup); $mulai->addMinutes(self::LANGKAH_MENIT)) {
            if ($mulai->lt($paling_awal)) {
                continue;
            }

            $selesai = $mulai->copy()->addMinutes($durasi);

            $kosong = $units->filter(function (Unit $u) use ($booking, $sibukSesi, $mulai, $selesai, $jeda) {
                if (isset($sibukSesi[$u->id]) && $sibukSesi[$u->id]->copy()->addMinutes($jeda)->gt($mulai)) {
                    return false;
                }

                return ! $booking->contains(fn ($b) => $b->unit_id === $u->id
                    && $b->mulai_pada->lt($selesai->copy()->addMinutes($jeda))
                    && $b->selesai_pada->copy()->addMinutes($jeda)->gt($mulai));
            })->values();

            $hasil[] = ['jam' => $mulai->format('H:i'), 'mulai' => $mulai->copy(), 'unit' => $kosong];
        }

        return $hasil;
    }

    /** Jam buka & tutup pada tanggal (tutup boleh lewat tengah malam, misal 10:00-02:00) */
    private function jamOperasional(CarbonInterface $tanggal, array $a): array
    {
        $buka = Carbon::parse($tanggal->toDateString().' '.$a['jam_buka']);
        $tutup = Carbon::parse($tanggal->toDateString().' '.$a['jam_tutup']);

        if ($tutup->lte($buka)) {
            $tutup->addDay();
        }

        return [$buka, $tutup];
    }

    /** Unit yang sedang dipakai: sibuk sampai sesi paket berakhir; open billing diperkirakan 1 jam lagi */
    private function sibukSesi(Cabang $cabang): array
    {
        return Sesi::withoutGlobalScopes()->where('cabang_id', $cabang->id)->aktif()->get()
            ->mapWithKeys(fn (Sesi $s) => [$s->unit_id => $s->berakhir_pada && $s->berakhir_pada->isFuture()
                ? $s->berakhir_pada->copy()
                : now()->addHour()])
            ->all();
    }

    /* ---------------- Buat & kelola ---------------- */

    /**
     * @param  array{nama:string, telepon:string, mulai:string, durasi:int, tipe_konsol_id?:?string, unit_id?:?string, catatan?:?string}  $data
     */
    public function buat(Cabang $cabang, array $data, ?User $kasir = null): Booking
    {
        $a = $this->aturan($cabang->id);

        if (! $kasir && ! $a['aktif']) {
            throw new BillingException('Booking online belum dibuka di cabang ini.');
        }

        $nama = trim((string) ($data['nama'] ?? ''));
        $telepon = Member::normalisasiTelepon((string) ($data['telepon'] ?? ''));
        $durasi = (int) ($data['durasi'] ?? 0);

        if (mb_strlen($nama) < 2) {
            throw new BillingException('Nama wajib diisi.');
        }

        if (strlen($telepon) < 9 || strlen($telepon) > 15) {
            throw new BillingException('Nomor WhatsApp tidak valid.');
        }

        if ($durasi < 30 || $durasi > 720 || (! $kasir && ! in_array($durasi, $a['durasi'], true))) {
            throw new BillingException('Durasi tidak tersedia.');
        }

        $mulai = Carbon::parse($data['mulai'] ?? '');

        if (! $kasir && $mulai->gt(now()->addDays($a['maks_hari'])->endOfDay())) {
            throw new BillingException("Booking paling jauh {$a['maks_hari']} hari ke depan.");
        }

        return DB::transaction(function () use ($cabang, $data, $kasir, $a, $nama, $telepon, $durasi, $mulai) {
            // Kunci unit cabang supaya dua pemesan tidak mendapat unit yang sama
            Unit::withoutGlobalScopes()->where('cabang_id', $cabang->id)->lockForUpdate()->get(['id']);

            if (! $kasir) {
                $aktif = Booking::withoutGlobalScopes()->where('cabang_id', $cabang->id)->where('telepon', $telepon)
                    ->aktif()->where('selesai_pada', '>', now())->count();

                if ($aktif >= $a['maks_aktif_per_hp']) {
                    throw new BillingException("Nomor ini sudah punya {$aktif} booking aktif.");
                }
            }

            $slot = collect($this->slot($cabang, $mulai->copy()->startOfDay(), $durasi, $data['tipe_konsol_id'] ?? null, (bool) $kasir))
                ->first(fn ($s) => $s['mulai']->equalTo($mulai));

            if (! $slot || $slot['unit']->isEmpty()) {
                throw new BillingException('Jam ini sudah penuh. Pilih jam lain.');
            }

            $unit = ! empty($data['unit_id'])
                ? ($slot['unit']->firstWhere('id', $data['unit_id']) ?? throw new BillingException('Unit itu tidak tersedia pada jam ini.'))
                : $slot['unit']->first();

            $tarif = rescue(fn () => app(BillingService::class)->tarifPerJam($unit), 0, false);
            $member = Member::withoutGlobalScopes()->where('tenant_id', $cabang->tenant_id)->where('telepon', $telepon)->where('is_active', true)->first();

            return Booking::create([
                'tenant_id' => $cabang->tenant_id,
                'cabang_id' => $cabang->id,
                'unit_id' => $unit->id,
                'member_id' => $member?->id,
                'kode' => $this->kodeBaru(),
                'nama' => $nama,
                'telepon' => $telepon,
                'mulai_pada' => $mulai,
                'selesai_pada' => $mulai->copy()->addMinutes($durasi),
                'durasi_menit' => $durasi,
                'perkiraan_harga' => intdiv($tarif * $durasi + 59, 60),
                'catatan' => trim((string) ($data['catatan'] ?? '')) ?: null,
                'status' => $kasir || $a['konfirmasi_otomatis'] ? 'dikonfirmasi' : 'menunggu',
                'sumber' => $kasir ? 'kasir' : 'online',
                'user_id' => $kasir?->id,
            ]);
        });
    }

    public function konfirmasi(Booking $b, User $user): Booking
    {
        if ($b->status !== 'menunggu') {
            throw new BillingException('Booking ini tidak menunggu konfirmasi.');
        }

        $b->update(['status' => 'dikonfirmasi', 'user_id' => $user->id]);

        return $b;
    }

    public function batal(Booking $b, string $alasan, ?User $user = null): Booking
    {
        if (! $b->isAktif()) {
            throw new BillingException('Booking ini sudah tidak aktif.');
        }

        $b->update(['status' => 'batal', 'alasan_batal' => mb_substr(trim($alasan), 0, 255) ?: null, 'user_id' => $user?->id ?? $b->user_id]);

        return $b;
    }

    public function tidakDatang(Booking $b): Booking
    {
        if (! $b->isAktif()) {
            throw new BillingException('Booking ini sudah tidak aktif.');
        }

        $b->update(['status' => 'tidak_datang']);

        return $b;
    }

    /** Dipanggil setelah sesi dimulai dari booking */
    public function checkin(string $bookingId, Sesi $sesi): void
    {
        Booking::withoutGlobalScopes()->whereKey($bookingId)->whereIn('status', Booking::AKTIF)
            ->update(['status' => 'checkin', 'sesi_id' => $sesi->id, 'unit_id' => $sesi->unit_id, 'updated_at' => now()]);
    }

    /** Booking yang lewat toleransi tanpa datang -> tidak_datang (slot dilepas). Return jumlah. */
    public function tandaiKedaluwarsa(): int
    {
        $jumlah = 0;

        Booking::withoutGlobalScopes()->aktif()->where('mulai_pada', '<', now())->get()
            ->each(function (Booking $b) use (&$jumlah) {
                if ($b->mulai_pada->copy()->addMinutes($this->aturan($b->cabang_id)['toleransi_menit'])->isPast()) {
                    $b->update(['status' => 'tidak_datang']);
                    $jumlah++;
                }
            });

        return $jumlah;
    }

    /** Kode pendek mudah dibaca (tanpa 0/O/1/I) */
    private function kodeBaru(): string
    {
        $huruf = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $kode = 'BK'.collect(range(1, 6))->map(fn () => $huruf[random_int(0, strlen($huruf) - 1)])->implode('');
        } while (Booking::withoutGlobalScopes()->where('kode', $kode)->exists());

        return $kode;
    }
}
