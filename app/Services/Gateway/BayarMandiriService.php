<?php

namespace App\Services\Gateway;

use App\Exceptions\BillingException;
use App\Models\PaketHarga;
use App\Models\PembayaranOnline;
use App\Models\Pengaturan;
use App\Models\Sesi;
use App\Models\Shift;
use App\Models\Unit;
use App\Services\Billing\BillingService;
use App\Services\Tv\NotifikasiTv;
use App\Support\Audit;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Bayar mandiri di TV lewat payment gateway (konsep IoT):
 *   1. Pelanggan scan QR di TV -> halaman HP -> ketik nominal (durasi dihitung dari tarif unit)
 *   2. QRIS nominal itu tampil di TV -> pelanggan bayar
 *   3. Pembayaran terdeteksi (cek status berkala / callback) -> sesi mulai otomatis (atau waktu ditambah
 *      bila waktu habis), lunas via "QRIS online", TV terbuka.
 * Hanya saat kas dibuka (sesi dicatat atas nama kasir yang membuka kas).
 */
final class BayarMandiriService
{
    public const MAKS_MENIT = 720;

    public function __construct(private PengaturanGateway $aturan, private BillingService $billing) {}

    /* ---------------- Konteks unit ---------------- */

    /** Kode acak unit untuk QR "Scan untuk main" (dibuat otomatis) */
    public function token(Unit $unit): string
    {
        if (! $unit->token_bayar) {
            $unit->token_bayar = Str::lower(Str::random(16));
            Unit::withoutGlobalScopes()->whereKey($unit->id)->update(['token_bayar' => $unit->token_bayar]);
        }

        return $unit->token_bayar;
    }

    public function urlHp(Unit $unit): string
    {
        return route('main', $this->token($unit));
    }

    /** Shift kas yang sedang buka di cabang (null = rental tutup) */
    public function shiftBuka(string $cabangId): ?Shift
    {
        return Shift::withoutGlobalScopes()->where('cabang_id', $cabangId)->where('status', Shift::STATUS_BUKA)
            ->with('user')->latest('dibuka_pada')->first();
    }

    /**
     * Apakah unit bisa dibayar mandiri sekarang.
     *
     * @return array{bisa:bool, jenis:?string, alasan:?string, sesi:?Sesi}
     */
    public function konteks(Unit $unit): array
    {
        $tidak = fn (string $alasan) => ['bisa' => false, 'jenis' => null, 'alasan' => $alasan, 'sesi' => null];

        if (! $this->aturan->bayarMandiriAktif($unit->cabang_id)) {
            return $tidak('Bayar mandiri belum diaktifkan.');
        }

        if (! $unit->is_active || $unit->status === Unit::STATUS_SERVIS) {
            return $tidak('Unit sedang tidak tersedia.');
        }

        if (! $this->shiftBuka($unit->cabang_id)) {
            return $tidak('Rental sedang tutup.');
        }

        if ($unit->isKosong()) {
            return ['bisa' => true, 'jenis' => 'mulai', 'alasan' => null, 'sesi' => null];
        }

        $sesi = Sesi::withoutGlobalScopes()->where('unit_id', $unit->id)->aktif()->latest('mulai_pada')->first();

        // Waktu habis (paket) & TV terkunci -> isi ulang
        if ($sesi && $sesi->isPaket() && $sesi->status === Sesi::STATUS_BERJALAN && $sesi->sisaDetik() === 0) {
            return ['bisa' => true, 'jenis' => 'isi_ulang', 'alasan' => null, 'sesi' => $sesi];
        }

        return $tidak('Unit sedang dipakai.');
    }

    /** Tarif per jam unit (null = belum diatur) */
    public function tarif(Unit $unit): ?int
    {
        return rescue(fn () => $this->billing->tarifPerJam($unit), null, false);
    }

    /**
     * Durasi yang didapat dari nominal: harga paket yang pas -> paket; selain itu nominal / tarif per jam.
     *
     * @return array{menit:int, paket:?PaketHarga}
     */
    public function hitung(Unit $unit, int $nominal, bool $bolehPaket = true): array
    {
        if ($bolehPaket) {
            $paket = PaketHarga::withoutGlobalScopes()->where('tenant_id', $unit->tenant_id)->untukUnit($unit)->aktif()
                ->where('jenis', PaketHarga::JENIS_PAKET)->where('harga', $nominal)->orderByDesc('durasi_menit')->first();

            if ($paket) {
                return ['menit' => (int) $paket->durasi_menit, 'paket' => $paket];
            }
        }

        $tarif = $this->tarif($unit);

        return ['menit' => $tarif ? (int) floor($nominal * 60 / $tarif) : 0, 'paket' => null];
    }

    /* ---------------- Buat tagihan ---------------- */

    public function buatTagihan(Unit $unit, int $nominal): PembayaranOnline
    {
        $k = $this->konteks($unit);

        if (! $k['bisa']) {
            throw new BillingException($k['alasan']);
        }

        // Isi ulang menambah waktu sesi yang sama: pakai tarif per jam (bukan paket)
        $h = $this->hitung($unit, $nominal, $k['jenis'] === 'mulai');
        $minimal = $this->aturan->minimalMenit($unit->cabang_id);

        if (! $h['paket'] && $h['menit'] < $minimal) {
            $tarif = (int) $this->tarif($unit);
            throw new BillingException('Minimal '.$minimal.' menit (Rp '.number_format(intdiv($tarif * $minimal + 59, 60), 0, ',', '.').').');
        }

        if ($h['menit'] > self::MAKS_MENIT) {
            throw new BillingException('Maksimal 12 jam sekali bayar.');
        }

        return DB::transaction(function () use ($unit, $nominal, $k, $h) {
            // Satu QR aktif per unit: QR lama dianggap tidak berlaku
            PembayaranOnline::withoutGlobalScopes()->where('unit_id', $unit->id)->where('status', 'menunggu')
                ->update(['status' => 'kedaluwarsa', 'catatan' => 'Diganti QR baru', 'updated_at' => now()]);

            $provider = $this->aturan->provider();
            $ref = 'BYR'.now()->format('ymdHis').Str::upper(Str::random(4));
            $judul = ($k['jenis'] === 'mulai' ? 'Main ' : 'Isi ulang ').$unit->nama.' '.$this->labelMenit($h['menit']);

            $qr = $this->aturan->driver($provider)->buatQris($ref, $nominal, $judul, $this->aturan->masaQris($unit->cabang_id));

            $p = PembayaranOnline::create([
                'tenant_id' => $unit->tenant_id,
                'cabang_id' => $unit->cabang_id,
                'unit_id' => $unit->id,
                'sesi_id' => $k['sesi']?->id,
                'transaksi_id' => $k['sesi']?->transaksi_id,
                'provider' => $provider,
                'jenis' => $k['jenis'],
                'merchant_ref' => $ref,
                'referensi' => $qr['referensi'],
                'nominal' => $nominal,
                'biaya' => $qr['biaya'],
                'menit' => $h['menit'],
                'paket_harga_id' => $h['paket']?->id,
                'qr_string' => $qr['qr_string'],
                'kedaluwarsa_pada' => $qr['kedaluwarsa'],
                'status' => 'menunggu',
                'data' => $qr['data'],
            ]);

            NotifikasiTv::unit($unit->id, 'bayar');

            return $p;
        });
    }

    /* ---------------- Deteksi pembayaran ---------------- */

    /** Cek status ke gateway (dibatasi tiap 4 detik per tagihan). Return tagihan terbaru. */
    public function periksa(PembayaranOnline $p): PembayaranOnline
    {
        if ($p->status === 'dibayar') {
            return $this->proses($p);
        }

        if ($p->status !== 'menunggu' || ! Cache::add('cek-bayar:'.$p->id, 1, 4)) {
            return $p;
        }

        try {
            $s = $this->aturan->driver($p->provider)->cekStatus((string) $p->referensi, $p->merchant_ref);
        } catch (Throwable $e) {
            report($e);

            return $p;
        }

        return $this->perbarui($p, $s['status'], $s['biaya']);
    }

    /** Terapkan status dari gateway (cek berkala atau callback) */
    public function perbarui(PembayaranOnline $p, string $status, ?int $biaya = null): PembayaranOnline
    {
        if ($status === 'dibayar' && in_array($p->status, ['menunggu', 'kedaluwarsa'], true)) {
            // Tetap diproses walau sudah lewat masa QR / diganti QR baru: uang pelanggan sudah masuk
            $p->update(['status' => 'dibayar', 'dibayar_pada' => now(), 'biaya' => $biaya ?? $p->biaya]);

            return $this->proses($p);
        }

        if ($p->status === 'menunggu' && ($status === 'kedaluwarsa' || $p->kedaluwarsa_pada->isPast())) {
            $p->update(['status' => 'kedaluwarsa']);
        } elseif ($p->status === 'menunggu' && $status === 'gagal') {
            $p->update(['status' => 'gagal']);
        }

        return $p;
    }

    /**
     * Pembayaran sukses -> mulai sesi / tambah waktu, lunas via QRIS online.
     * Aman dipanggil berkali-kali & dari dua server (ID sesi diturunkan dari nomor tagihan).
     */
    public function proses(PembayaranOnline $p): PembayaranOnline
    {
        return DB::transaction(function () use ($p) {
            $p = PembayaranOnline::withoutGlobalScopes()->whereKey($p->id)->lockForUpdate()->firstOrFail();

            if ($p->status !== 'dibayar') {
                return $p;
            }

            $unit = Unit::withoutGlobalScopes()->findOrFail($p->unit_id);
            $shift = $this->shiftBuka($unit->cabang_id);

            if (! $shift) {
                return $this->perluTindakan($p, 'Kas sudah tutup saat pembayaran masuk.');
            }

            $referensi = strtoupper($p->provider).' '.$p->referensi;

            try {
                $sesi = $p->jenis === 'isi_ulang' ? $this->sesiIsiUlang($p) : null;

                if ($sesi) {
                    $this->billing->tambahWaktu($sesi, $shift->user, $p->menit, harga: $p->nominal, dariSekarang: true);
                } elseif ($unit->isKosong()) {
                    $sesi = $this->billing->mulai($unit, $shift->user, [
                        'mode' => $p->paket_harga_id ? Sesi::MODE_PAKET : 'durasi',
                        'paket_harga_id' => $p->paket_harga_id,
                        'durasi_menit' => $p->menit,
                        'harga' => $p->nominal,
                        'pelanggan_nama' => 'Bayar mandiri (QRIS)',
                        'pilih_game_menit' => $p->jenis === 'mulai' ? max(0, min(15, (int) Pengaturan::ambil('sesi.pilih_game_menit', 5, $unit->cabang_id))) : 0,
                        'bayar_di_awal' => true,
                        'id_transaksi' => $this->idTurunan($p, 'transaksi'),
                        'id_sesi' => $this->idTurunan($p, 'sesi'),
                    ]);
                } else {
                    return $this->perluTindakan($p, "Unit {$unit->nama} sudah dipakai saat pembayaran masuk.");
                }

                $this->billing->bayarOnline($sesi->transaksi()->withoutGlobalScopes()->firstOrFail(), $shift->user, $p->nominal, $referensi);
            } catch (Throwable $e) {
                report($e);

                return $this->perluTindakan($p, 'Gagal memulai otomatis: '.$e->getMessage());
            }

            $p->update([
                'status' => 'selesai',
                'sesi_id' => $sesi->id,
                'transaksi_id' => $sesi->transaksi_id,
                'diproses_pada' => now(),
            ]);

            Audit::catat('bayar_mandiri', "Bayar mandiri {$unit->nama} Rp ".number_format($p->nominal, 0, ',', '.')." ({$this->labelMenit($p->menit)})", $p,
                ['provider' => $p->provider, 'referensi' => $p->referensi], anomali: false, tenantId: $p->tenant_id, cabangId: $p->cabang_id, userId: $shift->user_id);

            return $p;
        });
    }

    /** Pembayaran yang uangnya sudah masuk tapi tidak bisa diterapkan otomatis */
    public function perluTindakan(PembayaranOnline $p, string $alasan): PembayaranOnline
    {
        $p->update(['status' => 'perlu_tindakan', 'catatan' => mb_substr($alasan, 0, 255)]);

        Audit::catat('bayar_mandiri', "Bayar mandiri perlu tindakan: {$alasan}", $p, ['nominal' => $p->nominal],
            anomali: true, tenantId: $p->tenant_id, cabangId: $p->cabang_id);

        return $p;
    }

    /** Kasir menerapkan pembayaran "perlu tindakan" ke unit kosong lain */
    public function terapkanKeUnit(PembayaranOnline $p, Unit $unit): PembayaranOnline
    {
        if ($p->status !== 'perlu_tindakan') {
            throw new BillingException('Pembayaran ini tidak perlu tindakan.');
        }

        if (! $unit->isKosong()) {
            throw new BillingException("{$unit->nama} tidak kosong.");
        }

        $h = $this->hitung($unit, $p->nominal);
        $p->update([
            'unit_id' => $unit->id, 'jenis' => 'mulai', 'status' => 'dibayar', 'catatan' => null,
            'menit' => max(15, $h['menit']), 'paket_harga_id' => $h['paket']?->id,
        ]);

        return $this->proses($p);
    }

    /** Sesi bayar mandiri yang waktunya habis, lunas, dan tidak diisi ulang -> selesai otomatis (unit kosong) */
    public function selesaikanYangHabis(): int
    {
        $jumlah = 0;

        $sesiIds = PembayaranOnline::withoutGlobalScopes()->where('status', 'selesai')->whereNotNull('sesi_id')
            ->where('diproses_pada', '>', now()->subDays(2))->pluck('sesi_id')->unique();

        Sesi::withoutGlobalScopes()->whereIn('id', $sesiIds)->aktif()->whereNotNull('berakhir_pada')->with('transaksi')->get()
            ->each(function (Sesi $s) use (&$jumlah) {
                app(Tenancy::class)->set($s->tenant_id, $s->cabang_id);
                $batas = $this->aturan->selesaiOtomatisMenit($s->cabang_id);

                if ($s->berakhir_pada->copy()->addMinutes($batas)->isPast() && $s->transaksi?->isLunas()) {
                    $this->billing->selesai($s);
                    $jumlah++;
                }
            });

        return $jumlah;
    }

    /** Periksa semua tagihan menunggu (cadangan bila callback tidak sampai) */
    public function periksaSemua(): void
    {
        PembayaranOnline::withoutGlobalScopes()->whereIn('status', ['menunggu', 'dibayar'])
            ->where('created_at', '>', now()->subDay())->get()
            ->each(function (PembayaranOnline $p) {
                app(Tenancy::class)->set($p->tenant_id, $p->cabang_id); // kunci gateway milik tenant ini
                $this->periksa($p);
            });
    }

    public function labelMenit(int $menit): string
    {
        $j = intdiv($menit, 60);
        $m = $menit % 60;

        return trim(($j ? "{$j} jam " : '').($m ? "{$m} menit" : ''));
    }

    private function sesiIsiUlang(PembayaranOnline $p): ?Sesi
    {
        $s = $p->sesi_id ? Sesi::withoutGlobalScopes()->find($p->sesi_id) : null;

        return $s && $s->isAktif() && $s->isPaket() ? $s : null;
    }

    private function idTurunan(PembayaranOnline $p, string $jenis): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, "bayar-mandiri:{$p->merchant_ref}:{$jenis}")->toString();
    }
}
