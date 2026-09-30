<?php

namespace App\Services\Publik;

use App\Exceptions\BillingException;
use App\Models\Cabang;
use App\Models\Member;
use App\Models\Transaksi;
use App\Models\TransaksiItem;
use App\Models\Turnamen;
use App\Models\TurnamenPertandingan;
use App\Models\TurnamenPeserta;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\NomorTransaksi;
use App\Services\Billing\ShiftService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turnamen sistem gugur: pendaftaran (online / kasir), bayar biaya daftar (transaksi jenis turnamen),
 * bagan otomatis (peserta ganjil dapat bye), input skor, pemenang naik ke babak berikutnya.
 */
final class TurnamenService
{
    public function simpan(Cabang $cabang, array $data, ?Turnamen $t = null): Turnamen
    {
        $nama = trim((string) ($data['nama'] ?? ''));

        if (mb_strlen($nama) < 3) {
            throw new BillingException('Nama turnamen wajib diisi.');
        }

        $isi = [
            'nama' => $nama,
            'game' => trim((string) ($data['game'] ?? '')) ?: '-',
            'mulai_pada' => $data['mulai_pada'],
            'biaya_daftar' => max(0, (int) ($data['biaya_daftar'] ?? 0)),
            'kuota' => max(2, min(128, (int) ($data['kuota'] ?? 16))),
            'hadiah' => trim((string) ($data['hadiah'] ?? '')) ?: null,
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

    /** Bayar biaya pendaftaran di kasir: transaksi jenis turnamen (masuk omzet "lainnya" & kas) */
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

    /**
     * Tutup pendaftaran & buat bagan gugur. Peserta yang belum bayar tidak ikut.
     * Unggulan (angka kecil) ditempatkan terpisah; sisanya diacak.
     */
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

            $urut = $peserta->whereNotNull('unggulan')->sortBy('unggulan')->values()
                ->concat($peserta->whereNull('unggulan')->shuffle())->values();

            $ukuran = 2 ** (int) ceil(log($urut->count(), 2));
            $totalBabak = (int) log($ukuran, 2);
            $posisi = $this->urutanUnggulan($ukuran); // posisi slot untuk unggulan 1..n
            $slot = array_fill(0, $ukuran, null);

            foreach ($urut as $i => $p) {
                $slot[$posisi[$i]] = $p->id;
            }

            // Semua pertandingan semua babak dibuat di awal (peserta babak 2+ diisi saat pemenang naik)
            for ($babak = 1; $babak <= $totalBabak; $babak++) {
                $jumlah = $ukuran / (2 ** $babak);

                for ($n = 1; $n <= $jumlah; $n++) {
                    TurnamenPertandingan::create([
                        'tenant_id' => $t->tenant_id,
                        'turnamen_id' => $t->id,
                        'babak' => $babak,
                        'nomor' => $n,
                        'peserta_a_id' => $babak === 1 ? $slot[($n - 1) * 2] : null,
                        'peserta_b_id' => $babak === 1 ? $slot[($n - 1) * 2 + 1] : null,
                        'status' => 'menunggu',
                    ]);
                }
            }

            $t->update(['status' => 'berjalan']);

            // Bye: peserta tanpa lawan langsung naik
            foreach ($t->pertandingan()->where('babak', 1)->get() as $m) {
                if (($m->peserta_a_id === null) !== ($m->peserta_b_id === null)) {
                    $this->tetapkanPemenang($m, $m->peserta_a_id ?? $m->peserta_b_id, null, null);
                }
            }

            return $t->refresh();
        });
    }

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

        if ($skorA === $skorB) {
            throw new BillingException('Skor tidak boleh seri (sistem gugur). Masukkan hasil adu penalti / tambahan.');
        }

        $sudahAdaLanjutan = $this->pertandinganBerikut($m)?->pemenang_id;

        if ($m->status === 'selesai' && $sudahAdaLanjutan) {
            throw new BillingException('Babak berikutnya sudah selesai; hasil ini tidak bisa diubah.');
        }

        DB::transaction(fn () => $this->tetapkanPemenang($m, $skorA > $skorB ? $m->peserta_a_id : $m->peserta_b_id, $skorA, $skorB));
    }

    private function tetapkanPemenang(TurnamenPertandingan $m, string $pemenangId, ?int $skorA, ?int $skorB): void
    {
        $m->update(['pemenang_id' => $pemenangId, 'skor_a' => $skorA, 'skor_b' => $skorB, 'status' => 'selesai']);

        $berikut = $this->pertandinganBerikut($m);

        if (! $berikut) {
            Turnamen::withoutGlobalScopes()->whereKey($m->turnamen_id)->update(['status' => 'selesai', 'updated_at' => now()]);

            return;
        }

        $berikut->update([$m->nomor % 2 === 1 ? 'peserta_a_id' : 'peserta_b_id' => $pemenangId]);
    }

    private function pertandinganBerikut(TurnamenPertandingan $m): ?TurnamenPertandingan
    {
        return TurnamenPertandingan::withoutGlobalScopes()->where('turnamen_id', $m->turnamen_id)
            ->where('babak', $m->babak + 1)->where('nomor', (int) ceil($m->nomor / 2))->first();
    }

    /** Juara 1 (pemenang final), 2 (kalah final), 3 (kalah semifinal) */
    public function juara(Turnamen $t): array
    {
        // reorder(): relasi pertandingan() sudah diurutkan naik per babak
        $final = $t->pertandingan()->reorder()->orderByDesc('babak')->first();

        if (! $final?->pemenang_id) {
            return [];
        }

        $kalah = fn (TurnamenPertandingan $m) => $m->pemenang_id === $m->peserta_a_id ? $m->peserta_b_id : $m->peserta_a_id;
        $semi = $t->pertandingan()->where('babak', $final->babak - 1)->get();

        return array_filter([
            1 => TurnamenPeserta::find($final->pemenang_id)?->nama,
            2 => TurnamenPeserta::find($kalah($final))?->nama,
            3 => $semi->map(fn ($m) => TurnamenPeserta::find($kalah($m))?->nama)->filter()->implode(' & ') ?: null,
        ]);
    }

    /** Posisi slot bagan untuk unggulan 1..n (unggulan 1 & 2 baru bertemu di final) */
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
