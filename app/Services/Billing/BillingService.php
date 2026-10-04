<?php

namespace App\Services\Billing;

use App\Exceptions\BillingException;
use App\Models\Cabang;
use App\Models\Member;
use App\Models\PaketHarga;
use App\Models\Pembayaran;
use App\Models\Pengaturan;
use App\Models\Produk;
use App\Models\Sesi;
use App\Models\SesiLog;
use App\Models\Transaksi;
use App\Models\TransaksiItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\Member\MemberService;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Alur sesi billing: mulai, tambah waktu, pause, resume, pindah unit, selesai, bayar, batal.
 * Pemeriksaan hak akses & PIN dilakukan sebelum memanggil service ini.
 */
final class BillingService
{
    public function __construct(
        private NomorTransaksi $nomor,
        private ShiftService $shift,
        private KasService $kas,
        private KalkulatorOpenBilling $kalkulator,
        private MemberService $member,
    ) {}

    /* ================= MULAI ================= */

    /**
     * @param  array{mode:string, paket_harga_id?:string|null, durasi_menit?:int|null, pelanggan_nama?:string|null, member_id?:string|null, bayar_di_awal?:bool}  $data
     *                                                                                                                                                                   mode: paket (paket harga) | durasi (dari tarif per jam) | open (open billing)
     */
    public function mulai(Unit $unit, User $user, array $data): Sesi
    {
        return DB::transaction(function () use ($unit, $user, $data) {
            $unit = $this->kunciUnit($unit->id);

            if (! $unit->is_active) {
                throw new BillingException("Unit {$unit->nama} sedang nonaktif.");
            }

            if (! $unit->isKosong()) {
                throw new BillingException("Unit {$unit->nama} tidak kosong.");
            }

            $shift = $this->shift->wajibAktif($user, $unit->cabang_id);
            $cabang = Cabang::findOrFail($unit->cabang_id);
            $mode = $data['mode'] ?? '';

            // Waktu pilih game: TV sudah terbuka, tapi waktu sewa baru dihitung setelahnya (tidak ditagih)
            $pilihGame = max(0, min(15, (int) ($data['pilih_game_menit'] ?? 0)));
            $now = now()->addMinutes($pilihGame);

            $paket = null;
            $tarifPerJam = null;
            $durasiMenit = null;
            $berakhirPada = null;
            $hargaDurasi = null;

            if ($mode === Sesi::MODE_PAKET) {
                $paket = PaketHarga::withoutGlobalScopes()
                    ->where('tenant_id', $unit->tenant_id)
                    ->untukUnit($unit)
                    ->aktif()
                    ->where('jenis', PaketHarga::JENIS_PAKET)
                    ->find($data['paket_harga_id'] ?? null)
                    ?? throw new BillingException('Paket tidak ditemukan atau tidak berlaku untuk unit ini.');

                $durasiMenit = (int) $paket->durasi_menit;
                $berakhirPada = $now->copy()->addMinutes($durasiMenit);
            } elseif ($mode === 'durasi') {
                // Durasi bebas (30 menit, 1 jam, 2 jam, ...) dihitung dari tarif per jam
                $durasiMenit = (int) ($data['durasi_menit'] ?? 0);

                if ($durasiMenit < 15 || $durasiMenit > 720) {
                    throw new BillingException('Durasi harus antara 15 menit sampai 12 jam.');
                }

                $tarifPerJam = $this->tarifPerJam($unit);
                // Bayar mandiri: harga = nominal yang dibayar pelanggan (durasi sudah dihitung dari nominal)
                $hargaDurasi = isset($data['harga']) ? max(0, (int) $data['harga']) : intdiv($tarifPerJam * $durasiMenit + 59, 60);
                $berakhirPada = $now->copy()->addMinutes($durasiMenit);
                $mode = Sesi::MODE_PAKET; // hitung mundur seperti paket
            } elseif ($mode === Sesi::MODE_OPEN) {
                $tarifPerJam = $this->tarifPerJam($unit);
            } else {
                throw new BillingException('Mode billing tidak valid.');
            }

            $member = null;

            if (! empty($data['member_id'])) {
                $member = Member::withoutGlobalScopes()->where('tenant_id', $unit->tenant_id)->find($data['member_id'])
                    ?? throw new BillingException('Member tidak ditemukan.');

                if (! $member->is_active) {
                    throw new BillingException("Member {$member->nama} nonaktif.");
                }
            }

            // ID boleh ditentukan pemanggil (bayar mandiri: diturunkan dari referensi gateway, supaya
            // server lokal & cloud yang sama-sama memproses pembayaran menghasilkan sesi yang sama)
            $transaksi = new Transaksi([
                'tenant_id' => $unit->tenant_id,
                'cabang_id' => $unit->cabang_id,
                'shift_id' => $shift->id,
                'unit_id' => $unit->id,
                'user_id' => $user->id,
                'nomor' => $this->nomor->buat('BIL', $cabang),
                'jenis' => Transaksi::JENIS_BILLING,
                'status' => Transaksi::STATUS_BELUM_BAYAR,
                'member_id' => $member?->id,
                'pelanggan_nama' => ($data['pelanggan_nama'] ?? null) ?: $member?->nama,
            ]);

            if (! empty($data['id_transaksi'])) {
                $transaksi->id = $data['id_transaksi'];
            }

            $transaksi->save();

            $sesi = new Sesi([
                'tenant_id' => $unit->tenant_id,
                'cabang_id' => $unit->cabang_id,
                'unit_id' => $unit->id,
                'transaksi_id' => $transaksi->id,
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'paket_harga_id' => $paket?->id,
                'mode' => $mode,
                'tarif_per_jam' => $tarifPerJam,
                'durasi_menit' => $durasiMenit,
                'mulai_pada' => $now,
                'berakhir_pada' => $berakhirPada,
                'status' => Sesi::STATUS_BERJALAN,
                'bayar_di_awal' => (bool) ($data['bayar_di_awal'] ?? false),
            ]);

            if (! empty($data['id_sesi'])) {
                $sesi->id = $data['id_sesi'];
            }

            $sesi->save();

            if ($paket) {
                $this->tambahItem($transaksi, TransaksiItem::JENIS_SEWA, "Sewa {$unit->nama} - {$paket->nama}", (int) $paket->harga, $paket);
            } elseif ($hargaDurasi !== null) {
                $this->tambahItem($transaksi, TransaksiItem::JENIS_SEWA, "Sewa {$unit->nama} - ".Sesi::formatDurasi($durasiMenit * 60), $hargaDurasi);
            }

            $transaksi->hitungUlang();
            $unit->update(['status' => Unit::STATUS_MAIN]);

            $this->log($sesi, 'mulai', [
                'mode' => $mode,
                'paket' => $paket?->nama ?? ($hargaDurasi !== null ? Sesi::formatDurasi($durasiMenit * 60) : null),
                'tarif_per_jam' => $tarifPerJam,
                'nomor' => $transaksi->nomor,
                'pilih_game_menit' => $pilihGame ?: null,
            ], $user);

            return $sesi;
        });
    }

    /* ================= MULAI SEKARANG (akhiri waktu pilih game) ================= */

    public function mulaiSekarang(Sesi $sesi, User $user): Sesi
    {
        return DB::transaction(function () use ($sesi, $user) {
            $sesi = $this->kunciSesi($sesi->id);
            $this->pastikanAktif($sesi);

            if (! $sesi->sedangPilihGame()) {
                throw new BillingException('Waktu sewa sudah berjalan.');
            }

            $geser = (int) now()->diffInSeconds($sesi->mulai_pada);

            $sesi->mulai_pada = now();
            if ($sesi->berakhir_pada) {
                $sesi->berakhir_pada = $sesi->berakhir_pada->copy()->subSeconds($geser);
            }
            $sesi->versi_tagihan = $sesi->versi_tagihan + 1;
            $sesi->save();

            $this->log($sesi, 'mulai_sekarang', ['dipercepat_detik' => $geser], $user);

            return $sesi;
        });
    }

    /* ================= TAMBAH WAKTU (paket) ================= */

    /**
     * @param  int|null  $harga  harga khusus (bayar mandiri: sesuai nominal yang dibayar); null = dari tarif per jam
     * @param  bool  $dariSekarang  waktu habis & TV terkunci: tambahan dihitung dari sekarang, bukan dari jam habis
     */
    public function tambahWaktu(Sesi $sesi, User $user, int $menit, bool $gratis = false, ?string $alasan = null, ?int $harga = null, bool $dariSekarang = false): Sesi
    {
        if ($menit <= 0) {
            throw new BillingException('Durasi tambahan harus lebih dari 0 menit.');
        }

        if ($gratis && blank($alasan)) {
            throw new BillingException('Tambah waktu gratis wajib disertai alasan.');
        }

        return DB::transaction(function () use ($sesi, $user, $menit, $gratis, $alasan, $harga, $dariSekarang) {
            $sesi = $this->kunciSesi($sesi->id);
            $this->pastikanAktif($sesi);

            if (! $sesi->isPaket()) {
                throw new BillingException('Open billing tidak perlu tambah waktu, biaya dihitung saat selesai.');
            }

            $this->shift->wajibAktif($user, $sesi->cabang_id);

            $unit = Unit::withoutGlobalScopes()->findOrFail($sesi->unit_id);
            $harga = $gratis ? 0 : ($harga ?? intdiv($this->tarifPerJam($unit) * $menit + 59, 60));

            // Jam berakhir sebelum ditambah dicatat di log: dasar pembatalan & jejak audit
            $sebelum = $sesi->berakhir_pada->copy();
            $dasar = $dariSekarang && $sesi->berakhir_pada->isPast() ? now() : $sesi->berakhir_pada->copy();
            $sesi->berakhir_pada = $dasar->addMinutes($menit);
            $sesi->durasi_menit = (int) $sesi->durasi_menit + $menit;
            $sesi->versi_tagihan = $sesi->versi_tagihan + 1;
            $sesi->save();

            $transaksi = Transaksi::withoutGlobalScopes()->findOrFail($sesi->transaksi_id);
            $nama = "Tambah waktu {$menit} menit".($gratis ? ' (gratis)' : '');

            $item = $this->tambahItem($transaksi, TransaksiItem::JENIS_TAMBAH_WAKTU, $nama, $harga, null, $gratis ? "Alasan: {$alasan}" : null);
            $transaksi->hitungUlang();

            if ($transaksi->isLunas() && $transaksi->sisaTagihan() > 0) {
                $transaksi->update(['status' => Transaksi::STATUS_BELUM_BAYAR]);
                $transaksi->hitungUlang(); // diskon tier member ikut item baru
            }

            if ($gratis) {
                Audit::catat('waktu_gratis', "Tambah {$menit} menit gratis di {$transaksi->nomor}: {$alasan}", $transaksi, ['menit' => $menit, 'alasan' => $alasan], userId: $user->id);
            }

            $this->log($sesi, 'tambah_waktu', [
                'menit' => $menit,
                'harga' => $harga,
                'gratis' => $gratis,
                'alasan' => $alasan,
                'item_id' => $item->id,
                'berakhir_sebelum' => $sebelum->toIso8601String(),
                'berakhir_sesudah' => $sesi->berakhir_pada->toIso8601String(),
            ], $user);

            return $sesi;
        });
    }

    /* ================= BATAL TAMBAH WAKTU (salah pencet) ================= */

    /**
     * Membatalkan satu tambah waktu: jam berakhir dimundurkan sebanyak menit yang ditambahkan, biayanya jadi Rp0.
     *
     * Dihitung RELATIF dari jam berakhir sekarang (bukan menyalin "berakhir_sebelum"), supaya tetap benar bila
     * sesudahnya ada pause/resume (jam berakhir bergeser) atau tambah waktu lain. Bila tidak ada perubahan lain,
     * hasilnya sama persis dengan jam berakhir sebelum ditambah (dicatat di log untuk dicek).
     * Ditolak bila waktu tambahan itu sudah mulai terpakai atau biayanya sudah dibayar.
     */
    public function batalTambahWaktu(Sesi $sesi, string $logId, User $user, string $alasan): Sesi
    {
        if (mb_strlen(trim($alasan)) < 3) {
            throw new BillingException('Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($sesi, $logId, $user, $alasan) {
            $sesi = $this->kunciSesi($sesi->id);
            $this->pastikanAktif($sesi);

            if (! $sesi->isPaket() || ! $sesi->berakhir_pada) {
                throw new BillingException('Hanya sesi paket / durasi yang punya tambah waktu.');
            }

            $log = SesiLog::withoutGlobalScopes()->where('sesi_id', $sesi->id)->where('jenis', 'tambah_waktu')->find($logId)
                ?? throw new BillingException('Riwayat tambah waktu tidak ditemukan.');

            if (in_array($log->id, $this->tambahWaktuDibatalkan($sesi->id), true)) {
                throw new BillingException('Tambah waktu ini sudah dibatalkan.');
            }

            $menit = (int) ($log->data['menit'] ?? 0);
            $sebelum = $sesi->berakhir_pada->copy();
            $baru = $sebelum->copy()->subMinutes($menit);

            // Saat dijeda, waktu berhenti di jam jeda; selain itu acuannya sekarang
            $acuan = $sesi->dijeda_pada ?? now();

            if ($baru->lte($acuan)) {
                throw new BillingException("Waktu tambahan {$menit} menit sudah mulai terpakai, tidak bisa dibatalkan. Selesaikan sesi seperti biasa.");
            }

            $sesi->berakhir_pada = $baru;
            $sesi->durasi_menit = max(0, (int) $sesi->durasi_menit - $menit);
            $sesi->versi_tagihan = $sesi->versi_tagihan + 1;
            $sesi->save();

            // Item tagihan tidak dihapus (dilindungi database): dinolkan & diberi keterangan
            $transaksi = Transaksi::withoutGlobalScopes()->whereKey($sesi->transaksi_id)->lockForUpdate()->firstOrFail();
            $item = $this->itemTambahWaktu($transaksi, $log);
            $harga = (int) ($item?->subtotal ?? 0);

            if ($item) {
                $item->update([
                    'nama' => $item->nama.' (dibatalkan)',
                    'harga_satuan' => 0,
                    'subtotal' => 0,
                    'catatan' => trim(($item->catatan ? $item->catatan.' | ' : '').'Dibatalkan: '.trim($alasan)),
                ]);
            }

            $transaksi->hitungUlang();

            if ($transaksi->totalDibayar() > $transaksi->total) {
                throw new BillingException('Biaya tambah waktu ini sudah dibayar. Batalkan lewat menu Transaksi supaya uangnya tercatat dikembalikan.');
            }

            $this->log($sesi, 'batal_tambah_waktu', [
                'log_id' => $log->id,
                'menit' => $menit,
                'harga' => $harga,
                'alasan' => trim($alasan),
                'berakhir_sebelum' => $sebelum->toIso8601String(),
                'berakhir_sesudah' => $baru->toIso8601String(),
            ], $user);

            Audit::catat('batal_tambah_waktu', "Batal tambah {$menit} menit di {$transaksi->nomor} (Rp ".number_format($harga, 0, ',', '.')."): {$alasan}", $transaksi, [
                'menit' => $menit, 'harga' => $harga, 'alasan' => $alasan,
            ], userId: $user->id);

            return $sesi;
        });
    }

    /** Id log tambah_waktu yang sudah dibatalkan pada sesi ini */
    public function tambahWaktuDibatalkan(string $sesiId): array
    {
        return SesiLog::withoutGlobalScopes()->where('sesi_id', $sesiId)->where('jenis', 'batal_tambah_waktu')
            ->get(['data'])->pluck('data.log_id')->filter()->values()->all();
    }

    /** Item tagihan milik satu tambah waktu (log lama tanpa item_id: dicocokkan dari nama & harga) */
    private function itemTambahWaktu(Transaksi $transaksi, SesiLog $log): ?TransaksiItem
    {
        $q = TransaksiItem::withoutGlobalScopes()->where('transaksi_id', $transaksi->id)->where('jenis', TransaksiItem::JENIS_TAMBAH_WAKTU);

        if (! empty($log->data['item_id'])) {
            return $q->whereKey($log->data['item_id'])->first();
        }

        return $q->where('nama', 'like', 'Tambah waktu '.((int) $log->data['menit']).' menit%')
            ->where('nama', 'not like', '%(dibatalkan)')
            ->where('subtotal', (int) ($log->data['harga'] ?? 0))
            ->latest()->first();
    }

    /* ================= BATAL SESI (tidak jadi main) ================= */

    /** Sesi yang masih berjalan dibatalkan: tagihan batal (Rp0), unit kosong lagi, TV terkunci. */
    public function batalSesi(Sesi $sesi, User $user, string $alasan): Transaksi
    {
        if (! $sesi->isAktif()) {
            throw new BillingException('Sesi sudah selesai atau dibatalkan.');
        }

        return $this->batalkan(Transaksi::withoutGlobalScopes()->findOrFail($sesi->transaksi_id), $user, $alasan);
    }

    /**
     * Bonus waktu (kompensasi PS restart / hang / stik error), menit diketik operator.
     * - Paket: waktu berakhir mundur X menit, gratis (seperti tambah waktu gratis).
     * - Open billing: X menit tidak ditagih (dikurangkan dari durasi berjalan).
     * Tercatat sebagai item Rp0 berisi alasan, log aktivitas "waktu gratis" & log sesi.
     */
    public function bonusWaktu(Sesi $sesi, User $user, int $menit, string $alasan): Sesi
    {
        if ($menit < 1 || $menit > 240) {
            throw new BillingException('Bonus waktu 1–240 menit.');
        }

        if (blank($alasan)) {
            throw new BillingException('Bonus waktu wajib disertai alasan.');
        }

        if ($sesi->isPaket()) {
            return $this->tambahWaktu($sesi, $user, $menit, true, 'Bonus: '.$alasan);
        }

        return DB::transaction(function () use ($sesi, $user, $menit, $alasan) {
            $sesi = $this->kunciSesi($sesi->id);
            $this->pastikanAktif($sesi);
            $this->shift->wajibAktif($user, $sesi->cabang_id);

            $sesi->bonus_detik = (int) $sesi->bonus_detik + $menit * 60;
            $sesi->versi_tagihan = $sesi->versi_tagihan + 1;
            $sesi->save();

            $transaksi = Transaksi::withoutGlobalScopes()->findOrFail($sesi->transaksi_id);
            $this->tambahItem($transaksi, TransaksiItem::JENIS_TAMBAH_WAKTU, "Bonus waktu {$menit} menit", 0, null, "Alasan: {$alasan}");
            $transaksi->hitungUlang();

            Audit::catat('waktu_gratis', "Bonus {$menit} menit (open billing) di {$transaksi->nomor}: {$alasan}", $transaksi, ['menit' => $menit, 'alasan' => $alasan], userId: $user->id);
            $this->log($sesi, 'bonus_waktu', ['menit' => $menit, 'alasan' => $alasan], $user);

            return $sesi;
        });
    }

    /** Open billing berjalan: perkiraan biaya sewa sampai saat ini (null untuk paket / sesi selesai) */
    public function estimasiSewaOpen(Sesi $sesi): ?int
    {
        if ($sesi->isPaket() || ! $sesi->isAktif()) {
            return null;
        }

        return $this->kalkulator->hitung(
            (int) $sesi->tarif_per_jam,
            $sesi->durasiBerjalanDetik(),
            (int) Pengaturan::ambil('open_billing.blok_menit', 15, $sesi->cabang_id),
            (int) Pengaturan::ambil('open_billing.toleransi_menit', 5, $sesi->cabang_id),
            (int) Pengaturan::ambil('open_billing.minimal_menit', 60, $sesi->cabang_id),
            (int) Pengaturan::ambil('open_billing.pembulatan_rupiah', 0, $sesi->cabang_id),
        )['biaya'];
    }

    /* ================= PAUSE / RESUME ================= */

    public function pause(Sesi $sesi, User $user, ?string $alasan = null): Sesi
    {
        return DB::transaction(function () use ($sesi, $user, $alasan) {
            $sesi = $this->kunciSesi($sesi->id);

            if ($sesi->status !== Sesi::STATUS_BERJALAN) {
                throw new BillingException('Sesi tidak sedang berjalan.');
            }

            if ($sesi->sedangPilihGame()) {
                throw new BillingException('Masih waktu pilih game, waktu sewa belum berjalan.');
            }

            $maksKali = (int) Pengaturan::ambil('pause.maksimal_kali', 2, $sesi->cabang_id);

            if ($maksKali > 0 && $sesi->jumlah_jeda >= $maksKali) {
                throw new BillingException("Batas pause sudah tercapai ({$maksKali} kali).");
            }

            $sesi->update([
                'status' => Sesi::STATUS_DIJEDA,
                'dijeda_pada' => now(),
                'jumlah_jeda' => $sesi->jumlah_jeda + 1,
                'versi_tagihan' => $sesi->versi_tagihan + 1,
            ]);

            Unit::withoutGlobalScopes()->whereKey($sesi->unit_id)->update(['status' => Unit::STATUS_PAUSE]);

            $this->log($sesi, 'pause', ['alasan' => $alasan], $user);

            return $sesi;
        });
    }

    public function resume(Sesi $sesi, ?User $user = null): Sesi
    {
        return DB::transaction(function () use ($sesi, $user) {
            $sesi = $this->kunciSesi($sesi->id);

            if ($sesi->status !== Sesi::STATUS_DIJEDA) {
                throw new BillingException('Sesi tidak sedang dijeda.');
            }

            $jeda = $this->akhiriJeda($sesi);
            $sesi->status = Sesi::STATUS_BERJALAN;
            $sesi->versi_tagihan = $sesi->versi_tagihan + 1;
            $sesi->save();

            Unit::withoutGlobalScopes()->whereKey($sesi->unit_id)->update(['status' => Unit::STATUS_MAIN]);

            $this->log($sesi, 'resume', ['lama_jeda_detik' => $jeda], $user);

            return $sesi;
        });
    }

    /* ================= PINDAH UNIT ================= */

    public function pindahUnit(Sesi $sesi, Unit $tujuan, User $user, string $alasan, bool $unitLamaServis = false): Sesi
    {
        if (blank($alasan)) {
            throw new BillingException('Alasan pindah unit wajib diisi.');
        }

        return DB::transaction(function () use ($sesi, $tujuan, $user, $alasan, $unitLamaServis) {
            $sesi = $this->kunciSesi($sesi->id);
            $this->pastikanAktif($sesi);

            $asal = $this->kunciUnit($sesi->unit_id);
            $tujuan = $this->kunciUnit($tujuan->id);

            if ($tujuan->id === $asal->id) {
                throw new BillingException('Unit tujuan sama dengan unit asal.');
            }

            if ($tujuan->cabang_id !== $asal->cabang_id) {
                throw new BillingException('Unit tujuan berada di cabang lain.');
            }

            if (! $tujuan->is_active || ! $tujuan->isKosong()) {
                throw new BillingException("Unit {$tujuan->nama} tidak tersedia.");
            }

            $tujuan->update([
                'status' => $sesi->status === Sesi::STATUS_DIJEDA ? Unit::STATUS_PAUSE : Unit::STATUS_MAIN,
            ]);

            $asal->update([
                'status' => $unitLamaServis ? Unit::STATUS_SERVIS : Unit::STATUS_KOSONG,
            ]);

            $sesi->unit_id = $tujuan->id;
            $sesi->versi_tagihan = $sesi->versi_tagihan + 1;
            $sesi->save();

            Transaksi::withoutGlobalScopes()->whereKey($sesi->transaksi_id)->update(['unit_id' => $tujuan->id]);

            $this->log($sesi, 'pindah_unit', [
                'dari' => $asal->nama,
                'ke' => $tujuan->nama,
                'alasan' => $alasan,
                'unit_lama_servis' => $unitLamaServis,
            ], $user);

            return $sesi;
        });
    }

    /* ================= SELESAI ================= */

    public function selesai(Sesi $sesi, ?User $user = null): Sesi
    {
        return DB::transaction(function () use ($sesi, $user) {
            $sesi = $this->kunciSesi($sesi->id);
            $this->pastikanAktif($sesi);

            if ($sesi->status === Sesi::STATUS_DIJEDA) {
                $this->akhiriJeda($sesi);
            }

            $sesi->selesai_pada = now();
            $sesi->status = Sesi::STATUS_SELESAI;
            $sesi->versi_tagihan = $sesi->versi_tagihan + 1;
            $sesi->save();

            $transaksi = Transaksi::withoutGlobalScopes()->findOrFail($sesi->transaksi_id);
            $unit = Unit::withoutGlobalScopes()->findOrFail($sesi->unit_id);
            $durasiDetik = $sesi->durasiBerjalanDetik();

            $catatan = sprintf(
                'Masuk: %s | Keluar: %s | Durasi: %s',
                $sesi->mulai_pada->format('d/m H:i'),
                $sesi->selesai_pada->format('d/m H:i'),
                Sesi::formatDurasi($durasiDetik)
            );

            if ($sesi->isPaket()) {
                TransaksiItem::withoutGlobalScopes()
                    ->where('transaksi_id', $transaksi->id)
                    ->where('jenis', TransaksiItem::JENIS_SEWA)
                    ->update(['catatan' => $catatan]);
            } else {
                $hasil = $this->kalkulator->hitung(
                    (int) $sesi->tarif_per_jam,
                    $durasiDetik,
                    (int) Pengaturan::ambil('open_billing.blok_menit', 15, $sesi->cabang_id),
                    (int) Pengaturan::ambil('open_billing.toleransi_menit', 5, $sesi->cabang_id),
                    (int) Pengaturan::ambil('open_billing.minimal_menit', 60, $sesi->cabang_id),
                    (int) Pengaturan::ambil('open_billing.pembulatan_rupiah', 0, $sesi->cabang_id),
                );

                $this->tambahItem(
                    $transaksi,
                    TransaksiItem::JENIS_SEWA,
                    "Sewa {$unit->nama} - Open Billing ({$hasil['menit_ditagih']} menit)",
                    $hasil['biaya'],
                    null,
                    $catatan
                );
            }

            $transaksi->hitungUlang();

            if ($transaksi->total === 0 || $transaksi->sisaTagihan() === 0) {
                $this->lunasi($transaksi);
                $this->member->sinkronManfaat($transaksi, $user);
                $unit->update(['status' => Unit::STATUS_KOSONG]);
            } else {
                $unit->update(['status' => Unit::STATUS_MENUNGGU_BAYAR]);
            }

            $this->log($sesi, 'selesai', [
                'durasi_detik' => $durasiDetik,
                'total' => $transaksi->total,
            ], $user);

            return $sesi;
        });
    }

    /* ================= BAYAR ================= */

    /**
     * @param  array<int, array{metode:string, jumlah:int, diterima?:int|null, referensi?:string|null}>  $bayar
     */
    public function bayar(Transaksi $transaksi, User $user, array $bayar): Transaksi
    {
        return DB::transaction(function () use ($transaksi, $user, $bayar) {
            $transaksi = Transaksi::withoutGlobalScopes()->whereKey($transaksi->id)->lockForUpdate()->firstOrFail();

            if ($transaksi->isDibatalkan()) {
                throw new BillingException('Transaksi sudah dibatalkan.');
            }

            $sisa = $transaksi->sisaTagihan();

            if ($transaksi->isLunas()) {
                throw new BillingException('Transaksi sudah lunas.');
            }

            // Tagihan Rp0 (misal tertutup potongan poin/stamp) dilunasi tanpa pembayaran
            if ($bayar === [] && $sisa > 0) {
                throw new BillingException('Data pembayaran kosong.');
            }

            $shift = $this->shift->wajibAktif($user, $transaksi->cabang_id);
            $totalBayar = 0;
            $kembalian = 0;

            foreach ($bayar as $b) {
                $metode = $b['metode'] ?? '';
                $jumlah = (int) ($b['jumlah'] ?? 0);

                if (! in_array($metode, ['tunai', 'qris', 'transfer', 'saldo'], true)) {
                    throw new BillingException("Metode pembayaran '{$metode}' belum tersedia.");
                }

                if ($jumlah <= 0) {
                    throw new BillingException('Nominal pembayaran harus lebih dari 0.');
                }

                if ($metode === 'tunai') {
                    $diterima = (int) ($b['diterima'] ?? $jumlah);

                    if ($diterima < $jumlah) {
                        throw new BillingException('Uang tunai yang diterima kurang dari nominal.');
                    }

                    $kembalian += $diterima - $jumlah;
                }

                $totalBayar += $jumlah;
            }

            if ($totalBayar !== $sisa) {
                throw new BillingException(sprintf(
                    'Total pembayaran Rp %s tidak sama dengan sisa tagihan Rp %s.',
                    number_format($totalBayar, 0, ',', '.'),
                    number_format($sisa, 0, ',', '.')
                ));
            }

            foreach ($bayar as $b) {
                $tunai = $b['metode'] === 'tunai';

                $pembayaran = Pembayaran::create([
                    'tenant_id' => $transaksi->tenant_id,
                    'cabang_id' => $transaksi->cabang_id,
                    'transaksi_id' => $transaksi->id,
                    'shift_id' => $shift->id,
                    'user_id' => $user->id,
                    'metode' => $b['metode'],
                    'jumlah' => (int) $b['jumlah'],
                    'diterima' => $tunai ? (int) ($b['diterima'] ?? $b['jumlah']) : null,
                    'referensi' => $b['referensi'] ?? null,
                    'status' => 'sukses',
                    'dibayar_pada' => now(),
                ]);

                if ($tunai) {
                    $this->kas->catat($shift, 'penjualan', (int) $b['jumlah'], $user, $pembayaran, "Pembayaran {$transaksi->nomor}");
                }

                if ($b['metode'] === 'saldo') {
                    $this->member->potongSaldo($transaksi, $pembayaran, $user);
                }
            }

            $transaksi->total_bayar = $transaksi->totalDibayar();
            $transaksi->kembalian = $kembalian;
            $this->lunasi($transaksi);
            $this->member->sinkronManfaat($transaksi, $user);

            $sesi = Sesi::withoutGlobalScopes()->where('transaksi_id', $transaksi->id)->first();

            if ($sesi) {
                if ($sesi->status === Sesi::STATUS_SELESAI) {
                    Unit::withoutGlobalScopes()
                        ->whereKey($sesi->unit_id)
                        ->where('status', Unit::STATUS_MENUNGGU_BAYAR)
                        ->update(['status' => Unit::STATUS_KOSONG]);
                }

                $sesi->versi_tagihan = $sesi->versi_tagihan + 1;
                $sesi->save();

                $this->log($sesi, 'bayar', ['total' => $totalBayar, 'kembalian' => $kembalian], $user);
            }

            return $transaksi;
        });
    }

    /**
     * Pembayaran lewat payment gateway (QRIS di TV). Boleh sebagian: yang belum tertutup
     * tetap jadi sisa tagihan di kasir (misal F&B atau waktu tambahan manual dibayar tunai).
     */
    public function bayarOnline(Transaksi $transaksi, User $user, int $jumlah, string $referensi): Transaksi
    {
        return DB::transaction(function () use ($transaksi, $user, $jumlah, $referensi) {
            $transaksi = Transaksi::withoutGlobalScopes()->whereKey($transaksi->id)->lockForUpdate()->firstOrFail();

            if ($transaksi->isDibatalkan()) {
                throw new BillingException('Transaksi sudah dibatalkan.');
            }

            $jumlah = min($jumlah, $transaksi->sisaTagihan());

            if ($jumlah <= 0) {
                return $transaksi;
            }

            $shift = $this->shift->wajibAktif($user, $transaksi->cabang_id);

            Pembayaran::create([
                'tenant_id' => $transaksi->tenant_id,
                'cabang_id' => $transaksi->cabang_id,
                'transaksi_id' => $transaksi->id,
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'metode' => 'qris_gateway',
                'jumlah' => $jumlah,
                'referensi' => mb_substr($referensi, 0, 100),
                'status' => 'sukses',
                'dibayar_pada' => now(),
            ]);

            $transaksi->total_bayar = $transaksi->totalDibayar();

            if ($transaksi->sisaTagihan() <= 0) {
                $this->lunasi($transaksi);
                $this->member->sinkronManfaat($transaksi, $user);
            } else {
                $transaksi->save();
            }

            $sesi = Sesi::withoutGlobalScopes()->where('transaksi_id', $transaksi->id)->first();

            if ($sesi) {
                $sesi->versi_tagihan = $sesi->versi_tagihan + 1;
                $sesi->save();
                $this->log($sesi, 'bayar', ['total' => $jumlah, 'metode' => 'qris_gateway', 'referensi' => $referensi], $user);
            }

            return $transaksi;
        });
    }

    /* ================= BATAL ================= */

    public function batalkan(Transaksi $transaksi, User $user, string $alasan): Transaksi
    {
        if (mb_strlen(trim($alasan)) < 5) {
            throw new BillingException('Alasan pembatalan wajib diisi (minimal 5 karakter).');
        }

        return DB::transaction(function () use ($transaksi, $user, $alasan) {
            $transaksi = Transaksi::withoutGlobalScopes()->whereKey($transaksi->id)->lockForUpdate()->firstOrFail();

            if ($transaksi->isDibatalkan()) {
                throw new BillingException('Transaksi sudah dibatalkan sebelumnya.');
            }

            $sudahLunas = $transaksi->isLunas();

            $pembayaran = Pembayaran::withoutGlobalScopes()
                ->where('transaksi_id', $transaksi->id)
                ->where('status', 'sukses')
                ->get();

            $shift = $pembayaran->contains('metode', 'tunai')
                ? $this->shift->wajibAktif($user, $transaksi->cabang_id)
                : null;

            foreach ($pembayaran as $p) {
                $p->update(['status' => 'dibatalkan']);

                if ($p->metode === 'tunai' && $shift) {
                    $this->kas->catat($shift, 'pembatalan', -$p->jumlah, $user, $p, "Pembatalan {$transaksi->nomor}: {$alasan}");
                }
            }

            $transaksi->update([
                'status' => Transaksi::STATUS_DIBATALKAN,
                'dibatalkan_pada' => now(),
                'dibatalkan_oleh' => $user->id,
                'alasan_batal' => $alasan,
            ]);

            Audit::catat('batal_transaksi', "Batal {$transaksi->nomor} Rp ".number_format($transaksi->total, 0, ',', '.').": {$alasan}", $transaksi, [
                'nomor' => $transaksi->nomor, 'total' => $transaksi->total, 'sudah_lunas' => $sudahLunas, 'alasan' => $alasan,
            ], userId: $user->id);

            // Saldo, poin & stamp member dikembalikan; manfaat transaksi (poin, stamp, belanja) ditarik
            $this->member->saatDibatalkan($transaksi, $user);

            // Stok F&B dikembalikan
            $this->kembalikanStok($transaksi, $user);

            $sesi = Sesi::withoutGlobalScopes()->where('transaksi_id', $transaksi->id)->lockForUpdate()->first();

            if ($sesi) {
                $masihAktif = $sesi->isAktif();

                if ($masihAktif) {
                    if ($sesi->status === Sesi::STATUS_DIJEDA) {
                        $this->akhiriJeda($sesi);
                    }

                    $sesi->selesai_pada = now();
                    $sesi->status = Sesi::STATUS_DIBATALKAN;
                }

                $sesi->versi_tagihan = $sesi->versi_tagihan + 1;
                $sesi->save();

                // Kosongkan unit hanya jika masih dipakai sesi ini. Sesi lama yang sudah selesai & lunas
                // tidak boleh mengosongkan unit yang sekarang dipakai pelanggan lain.
                $statusMilikSesi = match (true) {
                    $masihAktif => [Unit::STATUS_MAIN, Unit::STATUS_PAUSE],
                    ! $sudahLunas => [Unit::STATUS_MENUNGGU_BAYAR],
                    default => [],
                };

                if ($statusMilikSesi !== []) {
                    Unit::withoutGlobalScopes()
                        ->whereKey($sesi->unit_id)
                        ->whereIn('status', $statusMilikSesi)
                        ->update(['status' => Unit::STATUS_KOSONG]);
                }

                $this->log($sesi, 'batal', ['alasan' => $alasan], $user);
            }

            return $transaksi;
        });
    }

    /* ================= HELPER ================= */

    /** Tarif per jam untuk unit: paket jenis per_jam yang paling spesifik. */
    public function tarifPerJam(Unit $unit): int
    {
        $paket = PaketHarga::withoutGlobalScopes()
            ->where('tenant_id', $unit->tenant_id)
            ->untukUnit($unit)
            ->aktif()
            ->where('jenis', PaketHarga::JENIS_PER_JAM)
            ->orderByRaw('cabang_id is null')
            ->orderByRaw('kategori_unit_id is null')
            ->orderByRaw('tipe_konsol_id is null')
            ->first();

        if (! $paket) {
            throw new BillingException("Tarif per jam untuk {$unit->nama} belum diatur.");
        }

        return (int) $paket->harga;
    }

    private function kunciUnit(string $unitId): Unit
    {
        return Unit::withoutGlobalScopes()->whereKey($unitId)->lockForUpdate()->firstOrFail();
    }

    private function kunciSesi(string $sesiId): Sesi
    {
        return Sesi::withoutGlobalScopes()->whereKey($sesiId)->lockForUpdate()->firstOrFail();
    }

    private function pastikanAktif(Sesi $sesi): void
    {
        if (! $sesi->isAktif()) {
            throw new BillingException('Sesi sudah selesai atau dibatalkan.');
        }
    }

    /** Tutup jeda yang sedang berjalan & geser jam berakhir paket. Return lama jeda (detik). */
    private function akhiriJeda(Sesi $sesi): int
    {
        if ($sesi->dijeda_pada === null) {
            return 0;
        }

        $jeda = max(0, (int) $sesi->dijeda_pada->diffInSeconds(now(), false));

        $sesi->total_jeda_detik = $sesi->total_jeda_detik + $jeda;

        if ($sesi->isPaket() && $sesi->berakhir_pada) {
            $sesi->berakhir_pada = $sesi->berakhir_pada->copy()->addSeconds($jeda);
        }

        $sesi->dijeda_pada = null;

        return $jeda;
    }

    private function tambahItem(
        Transaksi $transaksi,
        string $jenis,
        string $nama,
        int $harga,
        ?Model $referensi = null,
        ?string $catatan = null,
    ): TransaksiItem {
        return TransaksiItem::create([
            'tenant_id' => $transaksi->tenant_id,
            'cabang_id' => $transaksi->cabang_id,
            'transaksi_id' => $transaksi->id,
            'jenis' => $jenis,
            'referensi_type' => $referensi?->getMorphClass(),
            'referensi_id' => $referensi?->getKey(),
            'nama' => $nama,
            'qty' => 1,
            'harga_satuan' => $harga,
            'subtotal' => $harga,
            'catatan' => $catatan,
        ]);
    }

    /** Kembalikan stok produk dari transaksi yang dibatalkan */
    private function kembalikanStok(Transaksi $transaksi, User $user): void
    {
        $items = TransaksiItem::withoutGlobalScopes()
            ->where('transaksi_id', $transaksi->id)
            ->where('jenis', TransaksiItem::JENIS_PRODUK)
            ->where('referensi_type', (new Produk)->getMorphClass())
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $produk = Produk::withoutGlobalScopes()
            ->whereIn('id', $items->pluck('referensi_id'))
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            $p = $produk->get($item->referensi_id);

            // qty 0 = item sudah dibatalkan sendiri (stoknya sudah kembali)
            if ($p && $p->lacak_stok && $item->qty > 0) {
                app(StokService::class)->catat($p, $transaksi->cabang_id, $item->qty, 'pembatalan', $user, $item, "Pembatalan {$transaksi->nomor}");
            }
        }
    }

    private function lunasi(Transaksi $transaksi): void
    {
        $transaksi->status = Transaksi::STATUS_LUNAS;
        $transaksi->dibayar_pada = $transaksi->dibayar_pada ?? now();
        $transaksi->save();
    }

    private function log(Sesi $sesi, string $jenis, array $data = [], ?User $user = null): void
    {
        SesiLog::create([
            'tenant_id' => $sesi->tenant_id,
            'cabang_id' => $sesi->cabang_id,
            'sesi_id' => $sesi->id,
            'user_id' => $user?->id,
            'jenis' => $jenis,
            'data' => $data,
        ]);
    }
}
