<?php

namespace App\Services\Publik;

use App\Exceptions\BillingException;
use App\Models\Cabang;
use App\Models\Member;
use App\Models\Pengaturan;
use App\Models\Produk;
use App\Models\Transaksi;
use App\Models\TransaksiItem;
use App\Models\Turnamen;
use App\Models\TurnamenPertandingan;
use App\Models\TurnamenPeserta;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\NomorTransaksi;
use App\Services\Billing\ShiftService;
use App\Services\Billing\StokService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turnamen: pendaftaran (online / kasir), bayar biaya daftar (transaksi jenis turnamen, termasuk bundling F&B gratis),
 * format gugur / gugur ganda / liga / fase grup + gugur, input skor, klasemen, juara, rincian keuangan.
 *
 * Tahap pertandingan:
 *   gugur  - bagan gugur (format gugur, dan babak gugur setelah fase grup)
 *   atas / bawah / final - gugur ganda (bagan pemenang, bagan yang kalah sekali, grand final)
 *   liga / grup          - semua lawan semua (boleh seri)
 */
final class TurnamenService
{
    /** Tahap sistem gugur (skor tidak boleh seri, pemenang/kalah diteruskan) */
    private const TAHAP_GUGUR = ['gugur', 'atas', 'bawah', 'final'];

    public function simpan(Cabang $cabang, array $data, ?Turnamen $t = null): Turnamen
    {
        $nama = trim((string) ($data['nama'] ?? ''));

        if (mb_strlen($nama) < 3) {
            throw new BillingException('Nama turnamen wajib diisi.');
        }

        $format = (string) ($data['format'] ?? 'gugur');

        if (! isset(Turnamen::FORMAT[$format])) {
            throw new BillingException('Format turnamen tidak dikenal.');
        }

        if ($t && $t->status !== 'pendaftaran' && $t->status !== 'draft' && $format !== $t->format) {
            throw new BillingException('Format tidak bisa diganti setelah turnamen dimulai.');
        }

        $bonusId = ($data['bonus_produk_id'] ?? null) ?: null;
        $bonusQty = $bonusId ? max(1, min(20, (int) ($data['bonus_qty'] ?? 1))) : 0;

        if ($bonusId && ! Produk::query()->whereKey($bonusId)->exists()) {
            throw new BillingException('Produk bundling tidak ditemukan.');
        }

        $isi = [
            'nama' => $nama,
            'game' => trim((string) ($data['game'] ?? '')) ?: '-',
            'format' => $format,
            'jumlah_grup' => $format === 'grup_gugur' ? max(2, min(8, (int) ($data['jumlah_grup'] ?? 2))) : null,
            'lolos_per_grup' => $format === 'grup_gugur' ? max(1, min(2, (int) ($data['lolos_per_grup'] ?? 2))) : null,
            'putaran' => in_array($format, ['liga', 'grup_gugur'], true) ? max(1, min(2, (int) ($data['putaran'] ?? 1))) : 1,
            'mulai_pada' => $data['mulai_pada'],
            'biaya_daftar' => max(0, (int) ($data['biaya_daftar'] ?? 0)),
            'bonus_produk_id' => $bonusId,
            'bonus_qty' => $bonusQty,
            'kuota' => max(2, min(128, (int) ($data['kuota'] ?? 16))),
            'hadiah' => trim((string) ($data['hadiah'] ?? '')) ?: null,
            'total_hadiah' => max(0, (int) ($data['total_hadiah'] ?? 0)),
            'aturan' => trim((string) ($data['aturan'] ?? '')) ?: null,
            'daftar_online' => (bool) ($data['daftar_online'] ?? true),
        ];

        if ($t) {
            $t->update($isi);

            return $t;
        }

        return Turnamen::create($isi + [
            'tenant_id' => $cabang->tenant_id,
            'cabang_id' => $cabang->id,
            'slug' => Str::slug(Str::limit($nama, 60, '')).'-'.Str::lower(Str::random(4)),
            'status' => 'pendaftaran',
        ]);
    }

    public function daftar(Turnamen $t, string $nama, string $telepon, string $sumber = 'kasir'): TurnamenPeserta
    {
        $nama = trim($nama);
        $telepon = Member::normalisasiTelepon($telepon);

        if (mb_strlen($nama) < 2) {
            throw new BillingException('Nama / gamer tag wajib diisi.');
        }

        if (strlen($telepon) < 9) {
            throw new BillingException('Nomor WhatsApp tidak valid.');
        }

        return DB::transaction(function () use ($t, $nama, $telepon, $sumber) {
            $t = Turnamen::withoutGlobalScopes()->whereKey($t->id)->lockForUpdate()->firstOrFail();

            if ($t->status !== 'pendaftaran') {
                throw new BillingException('Pendaftaran turnamen sudah ditutup.');
            }

            if ($sumber === 'online' && ! $t->daftar_online) {
                throw new BillingException('Pendaftaran hanya di kasir.');
            }

            if ($t->penuh()) {
                throw new BillingException('Kuota peserta sudah penuh.');
            }

            if ($t->pesertaAktif()->where('telepon', $telepon)->exists()) {
                throw new BillingException('Nomor ini sudah terdaftar di turnamen ini.');
            }

            if ($t->pesertaAktif()->where('nama', $nama)->exists()) {
                throw new BillingException('Nama / gamer tag sudah dipakai peserta lain.');
            }

            $member = Member::withoutGlobalScopes()->where('tenant_id', $t->tenant_id)->where('telepon', $telepon)->first();

            return TurnamenPeserta::create([
                'tenant_id' => $t->tenant_id,
                'turnamen_id' => $t->id,
                'member_id' => $member?->id,
                'nama' => $nama,
                'telepon' => $telepon,
                'status' => $t->biaya_daftar > 0 ? 'terdaftar' : 'lunas',
                'sumber' => $sumber,
            ]);
        });
    }

    /**
     * Bayar biaya pendaftaran di kasir: transaksi jenis turnamen (masuk omzet "lainnya" & kas).
     * Bundling F&B: produk gratis jadi item Rp0 (stok berkurang, modal/HPP tercatat untuk laporan laba).
     */
    public function bayar(TurnamenPeserta $p, User $user, string $metode, ?int $diterima = null): Transaksi
    {
        $t = $p->turnamen;

        if ($p->status !== 'terdaftar' || $t->biaya_daftar <= 0) {
            throw new BillingException('Peserta ini tidak perlu membayar.');
        }

        return DB::transaction(function () use ($p, $t, $user, $metode, $diterima) {
            $cabang = Cabang::withoutGlobalScopes()->findOrFail($t->cabang_id);
            $shift = app(ShiftService::class)->wajibAktif($user, $cabang->id);

            $trx = Transaksi::create([
                'tenant_id' => $t->tenant_id,
                'cabang_id' => $t->cabang_id,
                'shift_id' => $shift->id,
                'user_id' => $user->id,
                'nomor' => app(NomorTransaksi::class)->buat('TRN', $cabang),
                'jenis' => Transaksi::JENIS_TURNAMEN,
                'status' => Transaksi::STATUS_BELUM_BAYAR,
                'member_id' => $p->member_id,
                'pelanggan_nama' => $p->nama,
            ]);

            TransaksiItem::create([
                'tenant_id' => $t->tenant_id,
                'cabang_id' => $t->cabang_id,
                'transaksi_id' => $trx->id,
                'jenis' => TransaksiItem::JENIS_LAINNYA,
                'referensi_type' => $t->getMorphClass(),
                'referensi_id' => $t->id,
                'nama' => 'Pendaftaran '.$t->nama,
                'qty' => 1,
                'harga_satuan' => $t->biaya_daftar,
                'subtotal' => $t->biaya_daftar,
            ]);

            $this->berikanBonus($t, $trx, $user);

            $trx->hitungUlang();

            app(BillingService::class)->bayar($trx, $user, [[
                'metode' => $metode,
                'jumlah' => $trx->sisaTagihan(),
                'diterima' => $metode === 'tunai' ? ($diterima ?? $trx->sisaTagihan()) : null,
            ]]);

            $p->update(['status' => 'lunas', 'transaksi_id' => $trx->id]);

            return $trx->refresh();
        });
    }

    /** Produk bundling gratis (mis. Teh Botol Kotak) sebagai item Rp0 + stok keluar */
    private function berikanBonus(Turnamen $t, Transaksi $trx, User $user): void
    {
        $produk = $t->bonus_produk_id ? Produk::withoutGlobalScopes()->find($t->bonus_produk_id) : null;

        if (! $produk || $t->bonus_qty < 1) {
            return;
        }

        $stok = app(StokService::class);
        $hpp = 0;

        if ($produk->lacak_stok) {
            $saldo = $stok->kunci($produk, $t->cabang_id);

            if (! Pengaturan::ambil('pos.izinkan_stok_minus', false, $t->cabang_id) && $saldo->qty < $t->bonus_qty) {
                throw new BillingException("Stok {$produk->nama} untuk bundling tidak cukup (sisa {$saldo->qty}).");
            }

            $hpp = (int) $saldo->hpp_rata;
        }

        $item = TransaksiItem::create([
            'tenant_id' => $t->tenant_id,
            'cabang_id' => $t->cabang_id,
            'transaksi_id' => $trx->id,
            'jenis' => TransaksiItem::JENIS_PRODUK,
            'referensi_type' => $produk->getMorphClass(),
            'referensi_id' => $produk->id,
            'nama' => $produk->nama.' (bonus turnamen)',
            'qty' => $t->bonus_qty,
            'harga_satuan' => 0,
            'hpp_satuan' => $hpp,
            'subtotal' => 0,
        ]);

        if ($produk->lacak_stok) {
            $stok->catat($produk, $t->cabang_id, -$t->bonus_qty, 'penjualan', $user, $item, "Bonus turnamen {$trx->nomor}");
        }
    }

    public function batalPeserta(TurnamenPeserta $p): void
    {
        if ($p->turnamen->status !== 'pendaftaran') {
            throw new BillingException('Turnamen sudah dimulai; peserta tidak bisa dihapus.');
        }

        if ($p->transaksi_id) {
            throw new BillingException('Peserta sudah membayar. Batalkan transaksinya dulu di menu Transaksi.');
        }

        $p->update(['status' => 'batal']);
    }

    /* ======================================================================
     * Keuangan: berapa uang masuk, modal bundling, dana bersih, sisa setelah hadiah
     * ====================================================================== */

    /**
     * @return array{perkiraan: array, realisasi: array, hpp_bonus: int, saran: array<int,int>}
     *                                                                                          perkiraan = jika kuota penuh & semua bayar; realisasi = peserta yang sudah lunas
     */
    public function keuangan(Turnamen $t): array
    {
        $hpp = 0;

        if ($t->bonus_produk_id && $t->bonus_qty > 0 && ($produk = Produk::withoutGlobalScopes()->find($t->bonus_produk_id))) {
            $hpp = $produk->lacak_stok ? (int) app(StokService::class)->kunci($produk, $t->cabang_id)->hpp_rata : 0;
        }

        $hitung = function (int $peserta) use ($t, $hpp) {
            $masuk = $peserta * $t->biaya_daftar;
            $modalBonus = $peserta * $t->bonus_qty * $hpp;
            $bersih = $masuk - $modalBonus;

            return [
                'peserta' => $peserta,
                'masuk' => $masuk,
                'modal_bonus' => $modalBonus,
                'bersih' => $bersih,
                'hadiah' => $t->total_hadiah,
                'sisa' => $bersih - $t->total_hadiah,
            ];
        };

        $lunas = $t->peserta()->where('status', 'lunas')->count();
        $perkiraan = $hitung($t->kuota);

        return [
            'perkiraan' => $perkiraan,
            'realisasi' => $hitung($lunas),
            'hpp_bonus' => $hpp,
            // Saran total hadiah dari dana bersih bila kuota penuh
            'saran' => collect([50, 60, 70])->mapWithKeys(fn ($persen) => [$persen => (int) (floor($perkiraan['bersih'] * $persen / 100 / 1000) * 1000)])->all(),
        ];
    }

    /* ======================================================================
     * Mulai: buat bagan / jadwal sesuai format
     * ====================================================================== */

    /** Tutup pendaftaran & buat bagan/jadwal. Peserta yang belum bayar tidak ikut. */
    public function mulai(Turnamen $t): Turnamen
    {
        return DB::transaction(function () use ($t) {
            $t = Turnamen::withoutGlobalScopes()->whereKey($t->id)->lockForUpdate()->firstOrFail();

            if ($t->status !== 'pendaftaran') {
                throw new BillingException('Turnamen sudah dimulai atau selesai.');
            }

            $peserta = $t->peserta()->where('status', 'lunas')->get();

            if ($peserta->count() < 2) {
                throw new BillingException('Minimal 2 peserta lunas untuk memulai.');
            }

            // Unggulan (angka kecil) dulu, sisanya acak
            $urut = $peserta->whereNotNull('unggulan')->sortBy('unggulan')->values()
                ->concat($peserta->whereNull('unggulan')->shuffle())->values();

            match ($t->format) {
                'gugur_ganda' => $this->buatGugurGanda($t, $urut->pluck('id')->all()),
                'liga' => $this->buatLiga($t, $urut->pluck('id')->all(), 'liga', null),
                'grup_gugur' => $this->buatGrup($t, $urut),
                default => $this->buatGugur($t, $urut->pluck('id')->all()),
            };

            $t->update(['status' => 'berjalan']);
            $this->selesaikanOtomatis($t);

            return $t->refresh();
        });
    }

    /** Bagan gugur: semua babak dibuat di awal; peserta babak 2+ diisi saat pemenang naik */
    private function buatGugur(Turnamen $t, array $urutId, string $tahap = 'gugur'): void
    {
        $ukuran = 2 ** (int) ceil(log(max(2, count($urutId)), 2));
        $totalBabak = (int) log($ukuran, 2);
        $slot = $this->tempatkanUnggulan($urutId, $ukuran);

        for ($babak = 1; $babak <= $totalBabak; $babak++) {
            for ($n = 1; $n <= $ukuran / (2 ** $babak); $n++) {
                $this->buatLaga($t, $tahap, null, $babak, $n, $babak === 1 ? $slot[($n - 1) * 2] : null, $babak === 1 ? $slot[($n - 1) * 2 + 1] : null);
            }
        }
    }

    /**
     * Gugur ganda untuk ukuran bagan S = 2^k:
     *   atas  : babak 1..k (seperti gugur biasa)
     *   bawah : babak 1..2(k-1); babak ganjil = sesama penghuni bagan bawah, babak genap = + yang kalah di bagan atas
     *   final : grand final (juara bagan atas vs juara bagan bawah)
     */
    private function buatGugurGanda(Turnamen $t, array $urutId): void
    {
        $ukuran = 2 ** (int) ceil(log(max(2, count($urutId)), 2));
        $k = (int) log($ukuran, 2);

        $this->buatGugur($t, $urutId, 'atas');

        for ($r = 1; $r <= 2 * ($k - 1); $r++) {
            $jumlah = intdiv($ukuran, 2 ** (intdiv($r + 1, 2) + 1));

            for ($n = 1; $n <= $jumlah; $n++) {
                $this->buatLaga($t, 'bawah', null, $r, $n, null, null);
            }
        }

        $this->buatLaga($t, 'final', null, 1, 1, null, null);
        // Final ulang (bracket reset): hanya dimainkan bila juara bagan bawah menang di grand final,
        // karena juara bagan atas baru kalah sekali. Tidak terpakai -> otomatis ditutup.
        $this->buatLaga($t, 'final', null, 2, 1, null, null);
    }

    /** Fase grup: bagi peserta ke grup (unggulan tersebar pola ular), lalu liga di tiap grup */
    private function buatGrup(Turnamen $t, Collection $urut): void
    {
        $jumlahGrup = (int) $t->jumlah_grup;
        $lolos = (int) $t->lolos_per_grup;

        if ($urut->count() < $jumlahGrup * max(2, $lolos)) {
            throw new BillingException("Peserta lunas ({$urut->count()}) terlalu sedikit untuk {$jumlahGrup} grup. Minimal ".($jumlahGrup * max(2, $lolos)).' peserta.');
        }

        $grup = [];

        foreach ($urut->values() as $i => $p) {
            $baris = intdiv($i, $jumlahGrup);
            $kolom = $i % $jumlahGrup;
            $g = $baris % 2 === 0 ? $kolom : $jumlahGrup - 1 - $kolom; // pola ular: unggulan tersebar
            $huruf = chr(65 + $g);
            $grup[$huruf][] = $p->id;
            $p->update(['grup' => $huruf]);
        }

        ksort($grup);
        $nomor = [];

        foreach ($grup as $huruf => $ids) {
            $this->buatLiga($t, $ids, 'grup', $huruf, $nomor);
        }
    }

    /**
     * Jadwal semua-lawan-semua (metode lingkaran). Peserta ganjil: satu peserta libur tiap babak.
     * Putaran 2 = pulang-pergi (posisi A/B dibalik).
     *
     * @param  array<string,int>  $nomor  penomoran berlanjut antar-grup per babak
     */
    private function buatLiga(Turnamen $t, array $ids, string $tahap, ?string $grup, array &$nomor = []): void
    {
        $daftar = array_values($ids);

        if (count($daftar) % 2 === 1) {
            $daftar[] = null; // libur
        }

        $n = count($daftar);
        $babakPerPutaran = $n - 1;

        for ($putaran = 0; $putaran < max(1, (int) $t->putaran); $putaran++) {
            $putar = $daftar;

            for ($r = 0; $r < $babakPerPutaran; $r++) {
                $babak = $putaran * $babakPerPutaran + $r + 1;

                for ($i = 0; $i < $n / 2; $i++) {
                    [$a, $b] = [$putar[$i], $putar[$n - 1 - $i]];

                    if ($a === null || $b === null) {
                        continue;
                    }

                    if (($r + $putaran) % 2 === 1) {
                        [$a, $b] = [$b, $a]; // seimbangkan posisi
                    }

                    $nomor[$babak] = ($nomor[$babak] ?? 0) + 1;
                    $this->buatLaga($t, $tahap, $grup, $babak, $nomor[$babak], $a, $b);
                }

                // Putar: elemen pertama tetap, sisanya bergeser
                $tetap = array_shift($putar);
                array_unshift($putar, array_pop($putar));
                array_unshift($putar, $tetap);
            }
        }
    }

    private function buatLaga(Turnamen $t, string $tahap, ?string $grup, int $babak, int $nomor, ?string $a, ?string $b): TurnamenPertandingan
    {
        return TurnamenPertandingan::create([
            'tenant_id' => $t->tenant_id,
            'turnamen_id' => $t->id,
            'tahap' => $tahap,
            'grup' => $grup,
            'babak' => $babak,
            'nomor' => $nomor,
            'peserta_a_id' => $a,
            'peserta_b_id' => $b,
            'status' => 'menunggu',
        ]);
    }

    /* ======================================================================
     * Pertandingan & skor
     * ====================================================================== */

    public function mulaiMain(TurnamenPertandingan $m, ?string $unitId): void
    {
        if (! $m->peserta_a_id || ! $m->peserta_b_id || $m->status === 'selesai') {
            throw new BillingException('Pertandingan belum siap dimainkan.');
        }

        $m->update(['status' => 'main', 'unit_id' => $unitId ?: $m->unit_id]);
    }

    public function hasil(TurnamenPertandingan $m, int $skorA, int $skorB): void
    {
        if (! $m->peserta_a_id || ! $m->peserta_b_id) {
            throw new BillingException('Pertandingan belum lengkap pesertanya.');
        }

        $t = Turnamen::withoutGlobalScopes()->findOrFail($m->turnamen_id);

        if ($t->status !== 'berjalan') {
            throw new BillingException('Turnamen tidak sedang berjalan.');
        }

        if (in_array($m->tahap, self::TAHAP_GUGUR, true)) {
            if ($skorA === $skorB) {
                throw new BillingException('Skor tidak boleh seri (sistem gugur). Masukkan hasil adu penalti / tambahan.');
            }

            if ($m->status === 'selesai' && $this->sudahDipakaiLanjutan($t, $m)) {
                throw new BillingException('Pertandingan lanjutan sudah selesai; hasil ini tidak bisa diubah.');
            }
        } elseif ($m->tahap === 'grup' && $t->pertandingan()->where('tahap', 'gugur')->exists()) {
            throw new BillingException('Babak gugur sudah dibuat; hasil fase grup tidak bisa diubah.');
        }

        DB::transaction(function () use ($t, $m, $skorA, $skorB) {
            if (in_array($m->tahap, self::TAHAP_GUGUR, true)) {
                $this->tetapkanPemenang($t, $m, $skorA > $skorB ? $m->peserta_a_id : $m->peserta_b_id, $skorA, $skorB);
            } else {
                // Liga / grup: seri boleh (pemenang kosong)
                $m->update([
                    'skor_a' => $skorA, 'skor_b' => $skorB, 'status' => 'selesai',
                    'pemenang_id' => $skorA === $skorB ? null : ($skorA > $skorB ? $m->peserta_a_id : $m->peserta_b_id),
                ]);
            }

            $this->selesaikanOtomatis($t);
        });
    }

    /** Pemenang & yang kalah diteruskan ke pertandingan tujuan (sesuai tahap) */
    private function tetapkanPemenang(Turnamen $t, TurnamenPertandingan $m, ?string $pemenangId, ?int $skorA, ?int $skorB): void
    {
        $m->update(['pemenang_id' => $pemenangId, 'skor_a' => $skorA, 'skor_b' => $skorB, 'status' => 'selesai']);

        $kalahId = $pemenangId === null ? null : ($pemenangId === $m->peserta_a_id ? $m->peserta_b_id : $m->peserta_a_id);
        $tujuan = $this->tujuan($t, $m);

        foreach (['menang' => $pemenangId, 'kalah' => $kalahId] as $jenis => $pesertaId) {
            if ($tujuan[$jenis] && $pesertaId) {
                [$tahap, $babak, $nomor, $sisi] = $tujuan[$jenis];
                $this->laga($t, $tahap, $babak, $nomor)?->update([$sisi === 'a' ? 'peserta_a_id' : 'peserta_b_id' => $pesertaId]);
            }
        }
    }

    /**
     * Ke mana pemenang & yang kalah dari pertandingan gugur diteruskan.
     *
     * @return array{menang: ?array{0:string,1:int,2:int,3:string}, kalah: ?array{0:string,1:int,2:int,3:string}}
     */
    private function tujuan(Turnamen $t, TurnamenPertandingan $m): array
    {
        $n = $m->nomor;
        $naik = (int) ceil($n / 2);
        $sisi = $n % 2 === 1 ? 'a' : 'b';
        $maks = fn (string $tahap) => (int) $t->pertandingan()->reorder()->where('tahap', $tahap)->max('babak');

        return match ($m->tahap) {
            'gugur' => [
                'menang' => $m->babak < $maks('gugur') ? ['gugur', $m->babak + 1, $naik, $sisi] : null,
                'kalah' => null,
            ],
            'atas' => [
                'menang' => $m->babak < $maks('atas') ? ['atas', $m->babak + 1, $naik, $sisi] : ['final', 1, 1, 'a'],
                'kalah' => match (true) {
                    $maks('atas') === 1 => ['final', 1, 1, 'b'],               // hanya 2 peserta
                    $m->babak === 1 => ['bawah', 1, $naik, $sisi],
                    default => ['bawah', 2 * ($m->babak - 1), $n, 'b'],
                },
            ],
            'bawah' => [
                'menang' => match (true) {
                    $m->babak === $maks('bawah') => ['final', 1, 1, 'b'],
                    $m->babak % 2 === 1 => ['bawah', $m->babak + 1, $n, 'a'],
                    default => ['bawah', $m->babak + 1, $naik, $sisi],
                },
                'kalah' => null,
            ],
            // Grand final: bila juara bagan bawah (sisi B) menang -> final ulang; selama belum selesai, final ulang menunggu
            'final' => $m->babak === 1 && ($m->status !== 'selesai' || ($m->pemenang_id && $m->pemenang_id === $m->peserta_b_id))
                ? ['menang' => ['final', 2, 1, 'b'], 'kalah' => ['final', 2, 1, 'a']]
                : ['menang' => null, 'kalah' => null],
            default => ['menang' => null, 'kalah' => null],
        };
    }

    private function laga(Turnamen $t, string $tahap, int $babak, int $nomor): ?TurnamenPertandingan
    {
        return TurnamenPertandingan::withoutGlobalScopes()->where('turnamen_id', $t->id)
            ->where('tahap', $tahap)->where('babak', $babak)->where('nomor', $nomor)->first();
    }

    /** Hasil sudah diteruskan & lanjutannya selesai -> tidak boleh diubah */
    private function sudahDipakaiLanjutan(Turnamen $t, TurnamenPertandingan $m): bool
    {
        foreach ($this->tujuan($t, $m) as $tujuan) {
            // Hanya laga lanjutan yang benar-benar dimainkan (ada skor); laga yang ditutup otomatis tidak mengunci
            $lanjutan = $tujuan ? $this->laga($t, $tujuan[0], $tujuan[1], $tujuan[2]) : null;

            if ($lanjutan?->status === 'selesai' && $lanjutan->skor_a !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rapikan otomatis setelah bagan dibuat / skor masuk:
     *  - laga gugur yang sumbernya sudah selesai tapi hanya punya 1 peserta (bye) -> menang otomatis; 0 peserta -> gugur
     *  - fase grup selesai -> buat babak gugur dari klasemen
     *  - laga terakhir selesai -> turnamen selesai
     */
    private function selesaikanOtomatis(Turnamen $t): void
    {
        $t->refresh();

        for ($ulang = 0; $ulang < 200; $ulang++) {
            $laga = $t->pertandingan()->whereIn('tahap', self::TAHAP_GUGUR)->get();
            $belum = $laga->where('status', '!=', 'selesai');

            // Slot yang masih menunggu hasil laga lain
            $menunggu = [];

            foreach ($belum as $m) {
                foreach ($this->tujuan($t, $m) as $tj) {
                    if ($tj) {
                        $menunggu["{$tj[0]}|{$tj[1]}|{$tj[2]}|{$tj[3]}"] = true;
                    }
                }
            }

            $berubah = false;

            foreach ($belum as $m) {
                $kunci = "{$m->tahap}|{$m->babak}|{$m->nomor}|";

                if (isset($menunggu[$kunci.'a']) || isset($menunggu[$kunci.'b'])) {
                    continue;
                }

                $ada = array_values(array_filter([$m->peserta_a_id, $m->peserta_b_id]));

                if (count($ada) === 1) {
                    $this->tetapkanPemenang($t, $m, $ada[0], null, null);
                    $berubah = true;
                    break;
                }

                if (count($ada) === 0) {
                    $this->tetapkanPemenang($t, $m, null, null, null); // laga kosong (dua sisi bye)
                    $berubah = true;
                    break;
                }
            }

            if (! $berubah) {
                break;
            }
        }

        // Fase grup selesai -> babak gugur silang antar grup
        if ($t->format === 'grup_gugur'
            && ! $t->pertandingan()->where('tahap', 'gugur')->exists()
            && $t->pertandingan()->where('tahap', 'grup')->exists()
            && ! $t->pertandingan()->where('tahap', 'grup')->where('status', '!=', 'selesai')->exists()) {
            $this->buatGugur($t, $this->lolosGrup($t));
            $this->selesaikanOtomatis($t);

            return;
        }

        if ($this->semuaSelesai($t)) {
            $t->update(['status' => 'selesai']);
        }
    }

    private function semuaSelesai(Turnamen $t): bool
    {
        $tahapAkhir = match ($t->format) {
            'liga' => 'liga',
            'gugur_ganda' => 'final',
            default => 'gugur',
        };

        $laga = $t->pertandingan()->where('tahap', $tahapAkhir);

        return (clone $laga)->exists() && ! (clone $laga)->where('status', '!=', 'selesai')->exists();
    }

    /**
     * Peserta lolos fase grup, urut unggulan: semua juara grup (A1, B1, ...) lalu runner-up (A2, B2, ...).
     * Penempatan unggulan mempertemukan grup berbeda di babak pertama (A1 vs B2, B1 vs A2, ...).
     */
    private function lolosGrup(Turnamen $t): array
    {
        $peringkat = [];

        foreach ($t->peserta()->whereNotNull('grup')->distinct()->orderBy('grup')->pluck('grup') as $g) {
            foreach (array_slice($this->klasemen($t, $g), 0, (int) $t->lolos_per_grup) as $i => $baris) {
                $peringkat[$i][] = $baris['peserta']->id;
            }
        }

        $urut = array_merge(...array_values($peringkat));

        return $this->hindariSesamaGrup($urut);
    }

    /** Geser runner-up bila babak pertama mempertemukan dua peserta dari grup yang sama */
    private function hindariSesamaGrup(array $urut): array
    {
        $ukuran = 2 ** (int) ceil(log(max(2, count($urut)), 2));
        $grup = TurnamenPeserta::withoutGlobalScopes()->whereIn('id', $urut)->pluck('grup', 'id');

        for ($coba = 0; $coba < 20; $coba++) {
            $slot = $this->tempatkanUnggulan($urut, $ukuran);
            $bentrok = null;

            for ($i = 0; $i < $ukuran; $i += 2) {
                if ($slot[$i] && $slot[$i + 1] && $grup[$slot[$i]] === $grup[$slot[$i + 1]]) {
                    $bentrok = max(array_search($slot[$i], $urut, true), array_search($slot[$i + 1], $urut, true));
                    break;
                }
            }

            if ($bentrok === null) {
                break;
            }

            // Tukar dengan peserta berperingkat sama/rendah berikutnya
            $tukar = $bentrok + 1 < count($urut) ? $bentrok + 1 : $bentrok - 1;
            [$urut[$bentrok], $urut[$tukar]] = [$urut[$tukar], $urut[$bentrok]];
        }

        return $urut;
    }

    /**
     * Klasemen liga / grup: menang 3, seri 1, kalah 0. Urut: poin, selisih gol, gol, nama.
     *
     * @return list<array{peserta: TurnamenPeserta, main:int, menang:int, seri:int, kalah:int, gm:int, gk:int, sg:int, poin:int}>
     */
    public function klasemen(Turnamen $t, ?string $grup = null): array
    {
        $peserta = $t->peserta()->where('status', 'lunas')
            ->when($grup, fn ($q) => $q->where('grup', $grup))
            ->get();

        $laga = $t->pertandingan()->where('tahap', $grup ? 'grup' : 'liga')
            ->when($grup, fn ($q) => $q->where('grup', $grup))
            ->where('status', 'selesai')->get();

        $tabel = $peserta->mapWithKeys(fn ($p) => [$p->id => [
            'peserta' => $p, 'main' => 0, 'menang' => 0, 'seri' => 0, 'kalah' => 0, 'gm' => 0, 'gk' => 0, 'sg' => 0, 'poin' => 0,
        ]])->all();

        foreach ($laga as $m) {
            foreach ([[$m->peserta_a_id, $m->skor_a, $m->skor_b], [$m->peserta_b_id, $m->skor_b, $m->skor_a]] as [$id, $gol, $kebobolan]) {
                if (! isset($tabel[$id])) {
                    continue;
                }

                $b = &$tabel[$id];
                $b['main']++;
                $b['gm'] += (int) $gol;
                $b['gk'] += (int) $kebobolan;
                $b['sg'] = $b['gm'] - $b['gk'];

                if ($gol > $kebobolan) {
                    $b['menang']++;
                    $b['poin'] += 3;
                } elseif ($gol === $kebobolan) {
                    $b['seri']++;
                    $b['poin'] += 1;
                } else {
                    $b['kalah']++;
                }

                unset($b);
            }
        }

        $hasil = array_values($tabel);
        usort($hasil, fn ($x, $y) => [$y['poin'], $y['sg'], $y['gm'], $x['peserta']->nama] <=> [$x['poin'], $x['sg'], $x['gm'], $y['peserta']->nama]);

        return $hasil;
    }

    /**
     * Susunan tampilan bagan/jadwal untuk kasir, halaman publik & billboard.
     *
     * @return list<array{judul:string, jenis:'klasemen'|'bagan', klasemen?:array, kolom:list<array{nama:string, laga:Collection}>}>
     */
    public function tampilan(Turnamen $t): array
    {
        $semua = $t->pertandingan()->with(['pesertaA:id,nama,grup', 'pesertaB:id,nama,grup', 'pemenang:id,nama', 'unit:id,nama'])->get();

        if ($semua->isEmpty()) {
            return [];
        }

        $kolom = function (Collection $laga, callable $nama) {
            return $laga->groupBy('babak')->map(fn ($ls, $babak) => ['nama' => $nama((int) $babak), 'laga' => $ls->values()])->values()->all();
        };

        $bagian = [];

        // Liga & fase grup: klasemen + jadwal per ronde
        foreach ($semua->whereIn('tahap', ['liga', 'grup'])->groupBy(fn ($m) => $m->grup ?? '') as $grup => $laga) {
            $bagian[] = [
                'judul' => $grup !== '' ? "Grup {$grup}" : 'Liga',
                'jenis' => 'klasemen',
                'klasemen' => $this->klasemen($t, $grup !== '' ? $grup : null),
                'lolos' => $grup !== '' ? (int) $t->lolos_per_grup : 0,
                'kolom' => $kolom($laga, fn ($b) => "Ronde {$b}"),
            ];
        }

        $gugur = $semua->where('tahap', 'gugur');

        if ($gugur->isNotEmpty()) {
            $total = (int) $gugur->max('babak');
            $bagian[] = ['judul' => $t->format === 'grup_gugur' ? 'Babak gugur' : 'Bagan', 'jenis' => 'bagan', 'kolom' => $kolom($gugur, fn ($b) => Turnamen::namaBabak($b, $total))];
        }

        foreach (['atas' => 'Bagan atas (belum pernah kalah)', 'bawah' => 'Bagan bawah (sudah kalah sekali)'] as $tahap => $judul) {
            $laga = $semua->where('tahap', $tahap);

            if ($laga->isNotEmpty()) {
                $total = (int) $laga->max('babak');
                $label = $tahap === 'atas' ? 'Atas' : 'Bawah';
                $bagian[] = ['judul' => $judul, 'jenis' => 'bagan', 'kolom' => $kolom($laga, fn ($b) => $b === $total ? "Final {$label}" : "{$label} R{$b}")];
            }
        }

        // Grand final (+ final ulang hanya bila dimainkan)
        $final = $semua->where('tahap', 'final')->filter(fn ($m) => $m->babak === 1 || $m->peserta_a_id || $m->peserta_b_id);

        if ($final->isNotEmpty()) {
            $bagian[] = ['judul' => 'Grand final', 'jenis' => 'bagan', 'kolom' => $kolom($final, fn ($b) => $b === 1 ? 'Grand final' : 'Final ulang')];
        }

        return $bagian;
    }

    /** Juara 1, 2, 3 sesuai format (hanya setelah turnamen selesai) */
    public function juara(Turnamen $t): array
    {
        if ($t->status !== 'selesai') {
            return [];
        }

        $nama = fn (?string $id) => $id ? TurnamenPeserta::withoutGlobalScopes()->find($id)?->nama : null;
        $kalah = fn (TurnamenPertandingan $m) => $m->pemenang_id === $m->peserta_a_id ? $m->peserta_b_id : $m->peserta_a_id;

        if ($t->format === 'liga') {
            $k = $this->klasemen($t);

            return array_filter([1 => $k[0]['peserta']->nama ?? null, 2 => $k[1]['peserta']->nama ?? null, 3 => $k[2]['peserta']->nama ?? null]);
        }

        if ($t->format === 'gugur_ganda') {
            // Final ulang dimainkan (punya pemenang) -> itu penentu juara; selain itu grand final
            $final = $t->pertandingan()->where('tahap', 'final')->whereNotNull('pemenang_id')->reorder()->orderByDesc('babak')->first();
            $bawah = $t->pertandingan()->where('tahap', 'bawah')->reorder()->orderByDesc('babak')->first();

            return array_filter([
                1 => $nama($final?->pemenang_id),
                2 => $final ? $nama($kalah($final)) : null,
                3 => $bawah?->pemenang_id ? $nama($kalah($bawah)) : null,
            ]);
        }

        // gugur & grup_gugur: dari babak gugur (reorder(): relasi sudah diurutkan naik per babak)
        $final = $t->pertandingan()->where('tahap', 'gugur')->reorder()->orderByDesc('babak')->first();

        if (! $final?->pemenang_id) {
            return [];
        }

        $semi = $t->pertandingan()->where('tahap', 'gugur')->where('babak', $final->babak - 1)->get();

        return array_filter([
            1 => $nama($final->pemenang_id),
            2 => $nama($kalah($final)),
            3 => $semi->map(fn ($m) => $m->pemenang_id ? $nama($kalah($m)) : null)->filter()->implode(' & ') ?: null,
        ]);
    }

    /** Slot bagan untuk urutan unggulan (unggulan 1 & 2 baru bertemu di final); slot kosong = bye */
    private function tempatkanUnggulan(array $urutId, int $ukuran): array
    {
        $posisi = $this->urutanUnggulan($ukuran);
        $slot = array_fill(0, $ukuran, null);

        foreach (array_values($urutId) as $i => $id) {
            $slot[$posisi[$i]] = $id;
        }

        return $slot;
    }

    /** Posisi slot bagan untuk unggulan 1..n */
    private function urutanUnggulan(int $ukuran): array
    {
        $urut = [0];

        while (count($urut) < $ukuran) {
            $n = count($urut) * 2;
            $baru = [];

            foreach ($urut as $s) {
                $baru[] = $s;
                $baru[] = $n - 1 - $s;
            }

            $urut = $baru;
        }

        // $urut[slot] = unggulan ke-; balik menjadi unggulan -> slot
        $posisi = [];

        foreach ($urut as $slot => $unggulan) {
            $posisi[$unggulan] = $slot;
        }

        ksort($posisi);

        return array_values($posisi);
    }
}
