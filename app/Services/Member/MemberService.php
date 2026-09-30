<?php

namespace App\Services\Member;

use App\Exceptions\BillingException;
use App\Models\Cabang;
use App\Models\Member;
use App\Models\MemberMutasi;
use App\Models\Pembayaran;
use App\Models\Sesi;
use App\Models\Transaksi;
use App\Models\TransaksiDiskon;
use App\Models\TransaksiItem;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\KasService;
use App\Services\Billing\NomorTransaksi;
use App\Services\Billing\ShiftService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Member: pendaftaran, saldo (top up & bayar), poin, stamp, tier.
 *
 * Semua perubahan saldo/poin/stamp/belanja lewat catat() -> baris member_mutasi (buku besar).
 * Manfaat transaksi (poin, stamp, belanja) disinkronkan idempoten dari status transaksi:
 * lunas = dapat, dibatalkan = ditarik kembali.
 */
final class MemberService
{
    private const KOLOM = [
        MemberMutasi::AKUN_SALDO => 'saldo',
        MemberMutasi::AKUN_POIN => 'poin',
        MemberMutasi::AKUN_STAMP => 'stamp',
        MemberMutasi::AKUN_BELANJA => 'total_belanja',
    ];

    public function __construct(
        private PengaturanMember $aturan,
        private NomorTransaksi $nomor,
        private ShiftService $shift,
        private KasService $kas,
    ) {}

    /* ================= DATA MEMBER ================= */

    /** @param array{nama:string, telepon:string, email?:?string, tanggal_lahir?:?string, catatan?:?string} $data */
    public function daftar(string $tenantId, ?string $cabangId, array $data): Member
    {
        $nama = trim($data['nama'] ?? '');
        $telepon = Member::normalisasiTelepon($data['telepon'] ?? '');

        if (mb_strlen($nama) < 2) {
            throw new BillingException('Nama member wajib diisi.');
        }

        if (strlen($telepon) < 9 || strlen($telepon) > 15) {
            throw new BillingException('Nomor HP tidak valid.');
        }

        return DB::transaction(function () use ($tenantId, $cabangId, $data, $nama, $telepon) {
            $ada = Member::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('telepon', $telepon)->first();

            if ($ada) {
                throw new BillingException("Nomor HP sudah terdaftar atas nama {$ada->nama} ({$ada->kode}).");
            }

            $member = new Member([
                'tenant_id' => $tenantId,
                'cabang_id' => $cabangId,
                'kode' => $this->kodeBaru($tenantId),
                'nama' => $nama,
                'telepon' => $telepon,
                'email' => $data['email'] ?? null,
                'tanggal_lahir' => $data['tanggal_lahir'] ?? null,
                'catatan' => $data['catatan'] ?? null,
                'is_active' => true,
            ]);
            $member->tier = $this->tierUntuk(0)['nama'];
            $member->save();

            return $member->refresh();
        });
    }

    public function ubah(Member $member, array $data): Member
    {
        $telepon = Member::normalisasiTelepon($data['telepon'] ?? $member->telepon);

        if (strlen($telepon) < 9 || strlen($telepon) > 15) {
            throw new BillingException('Nomor HP tidak valid.');
        }

        $bentrok = Member::withoutGlobalScopes()
            ->where('tenant_id', $member->tenant_id)
            ->where('telepon', $telepon)
            ->whereKeyNot($member->id)
            ->exists();

        if ($bentrok) {
            throw new BillingException('Nomor HP sudah dipakai member lain.');
        }

        $member->update([
            'nama' => trim($data['nama'] ?? $member->nama),
            'telepon' => $telepon,
            'email' => $data['email'] ?? $member->email,
            'tanggal_lahir' => $data['tanggal_lahir'] ?? $member->tanggal_lahir,
            'catatan' => $data['catatan'] ?? $member->catatan,
            'is_active' => (bool) ($data['is_active'] ?? $member->is_active),
        ]);

        return $member;
    }

    /** Tier sesuai total belanja: ['nama', 'min_belanja', 'diskon_persen'] */
    public function tierUntuk(int $totalBelanja): array
    {
        $hasil = $this->aturan->tier()[0];

        foreach ($this->aturan->tier() as $t) {
            if ($totalBelanja >= $t['min_belanja']) {
                $hasil = $t;
            }
        }

        return $hasil;
    }

    /** Tier berikutnya & kekurangan belanja, null jika sudah tertinggi */
    public function tierBerikutnya(Member $member): ?array
    {
        foreach ($this->aturan->tier() as $t) {
            if ($t['min_belanja'] > $member->total_belanja) {
                return $t + ['kurang' => $t['min_belanja'] - $member->total_belanja];
            }
        }

        return null;
    }

    public function diskonPersen(Member $member): int
    {
        foreach ($this->aturan->tier() as $t) {
            if ($t['nama'] === $member->tier) {
                return $t['diskon_persen'];
            }
        }

        return 0;
    }

    /* ================= TRANSAKSI & MEMBER ================= */

    /** Pasang / lepas member pada transaksi yang belum dibayar */
    public function pasangMember(Transaksi $transaksi, ?Member $member): Transaksi
    {
        return DB::transaction(function () use ($transaksi, $member) {
            $transaksi = Transaksi::withoutGlobalScopes()->whereKey($transaksi->id)->lockForUpdate()->firstOrFail();

            if ($transaksi->status !== Transaksi::STATUS_BELUM_BAYAR) {
                throw new BillingException('Member hanya bisa diubah sebelum transaksi dibayar.');
            }

            if ($transaksi->jenis === Transaksi::JENIS_TOP_UP) {
                throw new BillingException('Member transaksi top up tidak bisa diubah.');
            }

            if ($member && $member->tenant_id !== $transaksi->tenant_id) {
                throw new BillingException('Member tidak ditemukan.');
            }

            if ($member && ! $member->is_active) {
                throw new BillingException("Member {$member->nama} nonaktif.");
            }

            if ($member?->id === $transaksi->member_id) {
                return $transaksi;
            }

            $tukarAktif = TransaksiDiskon::withoutGlobalScopes()
                ->where('transaksi_id', $transaksi->id)
                ->whereIn('jenis', ['poin', 'stamp'])
                ->where('nilai', '>', 0)
                ->exists();

            if ($tukarAktif) {
                throw new BillingException('Batalkan dulu penukaran poin/stamp sebelum mengganti member.');
            }

            $transaksi->member_id = $member?->id;

            if ($member && ! $transaksi->pelanggan_nama) {
                $transaksi->pelanggan_nama = $member->nama;
            }

            $transaksi->save();

            if (! $member) {
                $this->aturDiskonTier($transaksi, 0, 'Diskon member');
            }

            $transaksi->hitungUlang();

            return $transaksi;
        });
    }

    /** Diskon tier untuk transaksi belum bayar (dipanggil dari Transaksi::hitungUlang) */
    public function sinkronDiskonTier(Transaksi $transaksi): void
    {
        if (! $transaksi->member_id || $transaksi->status !== Transaksi::STATUS_BELUM_BAYAR) {
            return;
        }

        $member = Member::withoutGlobalScopes()->find($transaksi->member_id);

        if (! $member) {
            return;
        }

        $persen = $this->aturan->aktif() ? $this->diskonPersen($member) : 0;
        $nilai = intdiv($this->nilaiSewa($transaksi) * $persen, 100);

        $this->aturDiskonTier($transaksi, $nilai, "Diskon member {$member->tier} {$persen}%");
    }

    /* ================= SALDO ================= */

    /** Top up saldo: transaksi jenis top_up (bukan omzet), uang masuk kas jika tunai, + bonus */
    public function topUp(Member $member, User $user, Cabang $cabang, int $jumlah, string $metode, ?int $diterima = null, ?string $referensi = null): Transaksi
    {
        if (! in_array($metode, ['tunai', 'qris', 'transfer'], true)) {
            throw new BillingException('Metode top up tidak valid.');
        }

        if ($jumlah < $this->aturan->minTopUp()) {
            throw new BillingException('Minimal top up Rp '.number_format($this->aturan->minTopUp(), 0, ',', '.').'.');
        }

        if ($metode === 'tunai' && $diterima !== null && $diterima < $jumlah) {
            throw new BillingException('Uang tunai yang diterima kurang dari nominal.');
        }

        return DB::transaction(function () use ($member, $user, $cabang, $jumlah, $metode, $diterima, $referensi) {
            $member = $this->kunci($member->id);

            if (! $member->is_active) {
                throw new BillingException("Member {$member->nama} nonaktif.");
            }

            $shift = $this->shift->wajibAktif($user, $cabang->id);
            $tunai = $metode === 'tunai';

            $transaksi = Transaksi::create([
                'tenant_id' => $cabang->tenant_id,
                'cabang_id' => $cabang->id,
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'nomor' => $this->nomor->buat('TOP', $cabang),
                'jenis' => Transaksi::JENIS_TOP_UP,
                'status' => Transaksi::STATUS_BELUM_BAYAR,
                'member_id' => $member->id,
                'pelanggan_nama' => $member->nama,
            ]);

            TransaksiItem::create([
                'tenant_id' => $transaksi->tenant_id,
                'cabang_id' => $transaksi->cabang_id,
                'transaksi_id' => $transaksi->id,
                'jenis' => TransaksiItem::JENIS_LAINNYA,
                'referensi_type' => $member->getMorphClass(),
                'referensi_id' => $member->id,
                'nama' => "Top up saldo {$member->kode}",
                'qty' => 1,
                'harga_satuan' => $jumlah,
                'subtotal' => $jumlah,
            ]);

            $transaksi->hitungUlang();

            $pembayaran = Pembayaran::create([
                'tenant_id' => $transaksi->tenant_id,
                'cabang_id' => $transaksi->cabang_id,
                'transaksi_id' => $transaksi->id,
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'metode' => $metode,
                'jumlah' => $jumlah,
                'diterima' => $tunai ? ($diterima ?? $jumlah) : null,
                'referensi' => $referensi,
                'status' => 'sukses',
                'dibayar_pada' => now(),
            ]);

            if ($tunai) {
                $this->kas->catat($shift, 'topup', $jumlah, $user, $pembayaran, "Top up {$member->kode} {$member->nama}");
            }

            $transaksi->update([
                'status' => Transaksi::STATUS_LUNAS,
                'total_bayar' => $jumlah,
                'kembalian' => $tunai ? max(0, ($diterima ?? $jumlah) - $jumlah) : 0,
                'dibayar_pada' => now(),
            ]);

            $this->catat($member, MemberMutasi::AKUN_SALDO, 'topup', $jumlah, $user, $transaksi, $pembayaran, "Top up {$transaksi->nomor}");

            $bonus = $this->bonusUntuk($jumlah);

            if ($bonus > 0) {
                $this->catat($member, MemberMutasi::AKUN_SALDO, 'bonus', $bonus, $user, $transaksi, null, 'Bonus top up Rp '.number_format($jumlah, 0, ',', '.'));
            }

            return $transaksi;
        });
    }

    public function bonusUntuk(int $jumlah): int
    {
        foreach ($this->aturan->bonusTopUp() as $b) {
            if ($jumlah >= $b['min']) {
                return $b['bonus'];
            }
        }

        return 0;
    }

    /** Potong saldo untuk pembayaran (dipanggil BillingService::bayar di dalam DB transaction) */
    public function potongSaldo(Transaksi $transaksi, Pembayaran $pembayaran, User $user): void
    {
        if (! $transaksi->member_id) {
            throw new BillingException('Bayar pakai saldo hanya untuk transaksi member.');
        }

        $member = $this->kunci($transaksi->member_id);

        if (! $member->is_active) {
            throw new BillingException("Member {$member->nama} nonaktif.");
        }

        if ($member->saldo < $pembayaran->jumlah) {
            throw new BillingException('Saldo member tidak cukup (sisa Rp '.number_format($member->saldo, 0, ',', '.').').');
        }

        $this->catat($member, MemberMutasi::AKUN_SALDO, 'bayar', -$pembayaran->jumlah, $user, $transaksi, $pembayaran, "Bayar {$transaksi->nomor}");
    }

    /**
     * Transaksi dibatalkan: kembalikan saldo yang dipakai & poin/stamp yang ditukar, tarik manfaat.
     * Top up yang dibatalkan: tarik saldo top up + bonus (ditolak jika saldo sudah terpakai).
     */
    public function saatDibatalkan(Transaksi $transaksi, User $user): void
    {
        if (! $transaksi->member_id) {
            return;
        }

        $member = $this->kunci($transaksi->member_id);

        if ($transaksi->jenis === Transaksi::JENIS_TOP_UP) {
            $masuk = $this->bersih($transaksi, MemberMutasi::AKUN_SALDO, ['topup', 'bonus', 'batal']);

            if ($masuk > 0) {
                if ($member->saldo < $masuk) {
                    throw new BillingException('Top up tidak bisa dibatalkan: saldo member sudah terpakai (sisa Rp '.number_format($member->saldo, 0, ',', '.').').');
                }

                $this->catat($member, MemberMutasi::AKUN_SALDO, 'batal', -$masuk, $user, $transaksi, null, "Batal top up {$transaksi->nomor}");
            }

            return;
        }

        foreach ([MemberMutasi::AKUN_SALDO => 'bayar', MemberMutasi::AKUN_POIN => 'tukar', MemberMutasi::AKUN_STAMP => 'tukar'] as $akun => $jenis) {
            $keluar = -$this->bersih($transaksi, $akun, [$jenis, 'refund']);

            if ($keluar > 0) {
                $this->catat($member, $akun, 'refund', $keluar, $user, $transaksi, null, "Batal {$transaksi->nomor}");
            }
        }

        $this->sinkronManfaat($transaksi->refresh(), $user, $member);
    }

    /* ================= POIN & STAMP ================= */

    /** Tukar poin jadi potongan harga. Return nilai potongan. */
    public function tukarPoin(Transaksi $transaksi, int $poin, User $user): int
    {
        return DB::transaction(function () use ($transaksi, $poin, $user) {
            [$transaksi, $member] = $this->siapTukar($transaksi);

            $nilaiPoin = $this->aturan->nilaiPoin();

            if ($nilaiPoin <= 0) {
                throw new BillingException('Penukaran poin tidak aktif.');
            }

            if ($poin < $this->aturan->minTukarPoin()) {
                throw new BillingException("Minimal tukar {$this->aturan->minTukarPoin()} poin.");
            }

            if ($poin > $member->poin) {
                throw new BillingException("Poin tidak cukup (punya {$member->poin} poin).");
            }

            // Jangan melebihi sisa tagihan
            $maksPoin = intdiv($transaksi->sisaTagihan(), $nilaiPoin);

            if ($maksPoin <= 0) {
                throw new BillingException('Tidak ada sisa tagihan untuk dipotong poin.');
            }

            $poin = min($poin, $maksPoin);
            $nilai = $poin * $nilaiPoin;

            $mutasi = $this->catat($member, MemberMutasi::AKUN_POIN, 'tukar', -$poin, $user, $transaksi, null, "Tukar {$poin} poin di {$transaksi->nomor}");

            $this->buatDiskon($transaksi, 'poin', "Tukar {$poin} poin", $nilai, $mutasi, $user);
            $transaksi->hitungUlang();

            return $nilai;
        });
    }

    /** Tukar stamp jadi main gratis (potongan senilai hadiah menit x tarif per jam). Return nilai potongan. */
    public function tukarStamp(Transaksi $transaksi, User $user): int
    {
        return DB::transaction(function () use ($transaksi, $user) {
            [$transaksi, $member] = $this->siapTukar($transaksi);

            $target = $this->aturan->targetStamp();
            $menit = $this->aturan->hadiahStampMenit();

            if ($target <= 0 || $menit <= 0) {
                throw new BillingException('Penukaran stamp tidak aktif.');
            }

            if ($member->stamp < $target) {
                throw new BillingException("Stamp belum cukup ({$member->stamp}/{$target}).");
            }

            if (! $transaksi->unit_id) {
                throw new BillingException('Stamp hanya bisa ditukar untuk sewa unit.');
            }

            $sudah = TransaksiDiskon::withoutGlobalScopes()
                ->where('transaksi_id', $transaksi->id)->where('jenis', 'stamp')->where('nilai', '>', 0)->exists();

            if ($sudah) {
                throw new BillingException('Stamp sudah ditukar di transaksi ini.');
            }

            $sesi = Sesi::withoutGlobalScopes()->where('transaksi_id', $transaksi->id)->first();
            $tarif = $sesi?->tarif_per_jam ?: app(BillingService::class)->tarifPerJam(Unit::withoutGlobalScopes()->findOrFail($transaksi->unit_id));

            $nilai = min(intdiv($tarif * $menit, 60), $this->nilaiSewa($transaksi), $transaksi->sisaTagihan());

            if ($nilai <= 0) {
                throw new BillingException('Belum ada biaya sewa untuk dipotong stamp.');
            }

            $mutasi = $this->catat($member, MemberMutasi::AKUN_STAMP, 'tukar', -$target, $user, $transaksi, null, "Tukar {$target} stamp di {$transaksi->nomor}");

            $this->buatDiskon($transaksi, 'stamp', "Tukar {$target} stamp (gratis {$menit} menit)", $nilai, $mutasi, $user);
            $transaksi->hitungUlang();

            return $nilai;
        });
    }

    /** Batalkan penukaran poin/stamp sebelum transaksi dibayar */
    public function batalTukar(TransaksiDiskon $diskon, User $user): void
    {
        DB::transaction(function () use ($diskon, $user) {
            $diskon = TransaksiDiskon::withoutGlobalScopes()->whereKey($diskon->id)->lockForUpdate()->firstOrFail();
            $transaksi = Transaksi::withoutGlobalScopes()->whereKey($diskon->transaksi_id)->lockForUpdate()->firstOrFail();

            if (! in_array($diskon->jenis, ['poin', 'stamp'], true) || $diskon->nilai <= 0) {
                throw new BillingException('Penukaran tidak ditemukan.');
            }

            if ($transaksi->status !== Transaksi::STATUS_BELUM_BAYAR) {
                throw new BillingException('Transaksi sudah dibayar.');
            }

            $mutasi = MemberMutasi::withoutGlobalScopes()->find($diskon->referensi_id);

            if ($mutasi && $mutasi->jumlah < 0) {
                $member = $this->kunci($mutasi->member_id);
                $this->catat($member, $mutasi->akun, 'refund', -$mutasi->jumlah, $user, $transaksi, null, "Batal tukar di {$transaksi->nomor}");
            }

            // Baris diskon tidak bisa dihapus: nolkan nilainya
            $diskon->update(['nilai' => 0, 'nama' => $diskon->nama.' (dibatalkan)']);
            $transaksi->hitungUlang();
        });
    }

    /**
     * Sinkronkan manfaat transaksi (poin, stamp, belanja, kunjungan, tier) dengan statusnya.
     * Idempoten: aman dipanggil berkali-kali.
     */
    public function sinkronManfaat(Transaksi $transaksi, ?User $user = null, ?Member $member = null): void
    {
        if (! $transaksi->member_id || $transaksi->jenis === Transaksi::JENIS_TOP_UP || $transaksi->is_latihan) {
            return;
        }

        $member ??= $this->kunci($transaksi->member_id);
        $lunas = $transaksi->status === Transaksi::STATUS_LUNAS;
        $billing = $transaksi->jenis === Transaksi::JENIS_BILLING;
        $total = (int) $transaksi->total;

        $perPoin = $this->aturan->belanjaPerPoin();
        $target = $this->aturan->targetStamp();

        $harus = [
            MemberMutasi::AKUN_BELANJA => $lunas ? $total : 0,
            MemberMutasi::AKUN_POIN => $lunas && $perPoin > 0 ? intdiv($total, $perPoin) : 0,
            MemberMutasi::AKUN_STAMP => $lunas && $billing && $target > 0 && $total >= $this->aturan->minBelanjaStamp() ? 1 : 0,
        ];

        $belanjaSebelum = $this->bersih($transaksi, MemberMutasi::AKUN_BELANJA, ['dapat', 'batal']);

        foreach ($harus as $akun => $nilai) {
            $selisih = $nilai - $this->bersih($transaksi, $akun, ['dapat', 'batal']);

            if ($selisih !== 0) {
                $this->catat($member, $akun, $selisih > 0 ? 'dapat' : 'batal', $selisih, $user, $transaksi, null, $transaksi->nomor);
            }
        }

        if ($billing && ($belanjaSebelum > 0) !== ($harus[MemberMutasi::AKUN_BELANJA] > 0)) {
            $member->jumlah_kunjungan = max(0, $member->jumlah_kunjungan + ($lunas ? 1 : -1));
        }

        if ($lunas) {
            $member->terakhir_kunjungan = now();
        }

        $member->tier = $this->tierUntuk($member->total_belanja)['nama'];
        $member->save();
    }

    /* ================= KOREKSI ================= */

    /** Koreksi manual saldo/poin/stamp (wajib PIN penyetuju, dicek di pemanggil) */
    public function koreksi(Member $member, string $akun, int $jumlah, string $alasan, User $user, User $penyetuju): MemberMutasi
    {
        if (! in_array($akun, [MemberMutasi::AKUN_SALDO, MemberMutasi::AKUN_POIN, MemberMutasi::AKUN_STAMP], true)) {
            throw new BillingException('Jenis koreksi tidak valid.');
        }

        if ($jumlah === 0) {
            throw new BillingException('Jumlah koreksi tidak boleh 0.');
        }

        if (mb_strlen(trim($alasan)) < 5) {
            throw new BillingException('Alasan koreksi wajib diisi (minimal 5 karakter).');
        }

        return DB::transaction(function () use ($member, $akun, $jumlah, $alasan, $user, $penyetuju) {
            $member = $this->kunci($member->id);

            if ($akun === MemberMutasi::AKUN_SALDO && $member->saldo + $jumlah < 0) {
                throw new BillingException('Saldo tidak boleh minus.');
            }

            Audit::catat('koreksi_member', "Koreksi {$akun} {$member->kode} {$member->nama}: ".($jumlah > 0 ? '+' : '').$jumlah.' - '.trim($alasan), $member, [
                'akun' => $akun, 'jumlah' => $jumlah, 'penyetuju' => $penyetuju->name,
            ], userId: $user->id);

            return $this->catat($member, $akun, 'koreksi', $jumlah, $user, null, null, trim($alasan)." (disetujui {$penyetuju->name})");
        });
    }

    /* ================= HELPER ================= */

    private function catat(
        Member $member,
        string $akun,
        string $jenis,
        int $jumlah,
        ?User $user,
        ?Transaksi $transaksi = null,
        ?Pembayaran $pembayaran = null,
        ?string $keterangan = null,
    ): MemberMutasi {
        $kolom = self::KOLOM[$akun];
        $member->{$kolom} = (int) $member->{$kolom} + $jumlah;
        $member->save();

        return MemberMutasi::create([
            'tenant_id' => $member->tenant_id,
            'cabang_id' => $transaksi?->cabang_id ?? $member->cabang_id,
            'member_id' => $member->id,
            'akun' => $akun,
            'jenis' => $jenis,
            'jumlah' => $jumlah,
            'saldo_akhir' => $member->{$kolom},
            'transaksi_id' => $transaksi?->id,
            'pembayaran_id' => $pembayaran?->id,
            'user_id' => $user?->id,
            'keterangan' => $keterangan,
        ]);
    }

    /** Jumlah bersih mutasi satu akun untuk transaksi, dibatasi jenis tertentu */
    private function bersih(Transaksi $transaksi, string $akun, array $jenis): int
    {
        return (int) MemberMutasi::withoutGlobalScopes()
            ->where('transaksi_id', $transaksi->id)
            ->where('akun', $akun)
            ->whereIn('jenis', $jenis)
            ->sum('jumlah');
    }

    private function kunci(string $memberId): Member
    {
        return Member::withoutGlobalScopes()->whereKey($memberId)->lockForUpdate()->firstOrFail();
    }

    /** @return array{0:Transaksi, 1:Member} */
    private function siapTukar(Transaksi $transaksi): array
    {
        $transaksi = Transaksi::withoutGlobalScopes()->whereKey($transaksi->id)->lockForUpdate()->firstOrFail();

        if ($transaksi->status !== Transaksi::STATUS_BELUM_BAYAR) {
            throw new BillingException('Transaksi sudah dibayar atau dibatalkan.');
        }

        if (! $transaksi->member_id) {
            throw new BillingException('Pilih member dulu.');
        }

        if (! $this->aturan->aktif()) {
            throw new BillingException('Program member tidak aktif.');
        }

        $member = $this->kunci($transaksi->member_id);

        if (! $member->is_active) {
            throw new BillingException("Member {$member->nama} nonaktif.");
        }

        return [$transaksi, $member];
    }

    /** Nilai sewa (sewa + tambah waktu) pada transaksi */
    private function nilaiSewa(Transaksi $transaksi): int
    {
        return (int) TransaksiItem::withoutGlobalScopes()
            ->where('transaksi_id', $transaksi->id)
            ->whereIn('jenis', [TransaksiItem::JENIS_SEWA, TransaksiItem::JENIS_TAMBAH_WAKTU])
            ->sum('subtotal');
    }

    /** Diskon tier disimpan di satu baris jenis 'member' per transaksi (baris diskon tidak bisa dihapus) */
    private function aturDiskonTier(Transaksi $transaksi, int $nilai, string $nama): void
    {
        $row = TransaksiDiskon::withoutGlobalScopes()
            ->where('transaksi_id', $transaksi->id)
            ->where('jenis', 'member')
            ->first();

        if (! $row) {
            if ($nilai > 0) {
                $this->buatDiskon($transaksi, 'member', $nama, $nilai);
            }

            return;
        }

        if ($row->nilai !== $nilai || ($nilai > 0 && $row->nama !== $nama)) {
            $row->update(['nilai' => $nilai, 'nama' => $nilai > 0 ? $nama : 'Diskon member']);
        }
    }

    private function buatDiskon(Transaksi $transaksi, string $jenis, string $nama, int $nilai, ?MemberMutasi $referensi = null, ?User $user = null): TransaksiDiskon
    {
        return TransaksiDiskon::create([
            'tenant_id' => $transaksi->tenant_id,
            'cabang_id' => $transaksi->cabang_id,
            'transaksi_id' => $transaksi->id,
            'user_id' => $user?->id,
            'jenis' => $jenis,
            'nama' => $nama,
            'nilai' => $nilai,
            'referensi_type' => $referensi?->getMorphClass(),
            'referensi_id' => $referensi?->getKey(),
        ]);
    }

    private function kodeBaru(string $tenantId): string
    {
        // Kunci baris terakhir supaya dua kasir yang mendaftar bersamaan tidak mendapat kode sama
        $terakhir = Member::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('kode', 'like', 'M%')
            ->lockForUpdate()
            ->orderByRaw('CAST(SUBSTRING(kode, 2) AS UNSIGNED) DESC')
            ->value('kode');

        return sprintf('M%05d', ((int) substr((string) $terakhir, 1)) + 1);
    }
}
