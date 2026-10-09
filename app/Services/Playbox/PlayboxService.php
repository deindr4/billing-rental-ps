<?php

namespace App\Services\Playbox;

use App\Exceptions\BillingException;
use App\Jobs\KirimNotifikasi;
use App\Models\Cabang;
use App\Models\Pengaturan;
use App\Models\Penyewa;
use App\Models\Playbox;
use App\Models\SewaPlaybox;
use App\Models\Transaksi;
use App\Models\TransaksiItem;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\KasService;
use App\Services\Billing\NomorTransaksi;
use App\Services\Billing\ShiftService;
use App\Services\Notifikasi\Lonceng;
use App\Services\Notifikasi\PengaturanNotifikasi;
use App\Support\Audit;
use App\Support\FotoPrivat;
use App\Support\Koordinat;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sewa Playbox bawa pulang (bayar di muka):
 * - sewa: tagihan sewa (transaksi jenis sewa_luar, dibayar lewat dialog Pembayaran), deposit masuk kas sebagai titipan
 *   (kas_mutasi deposit_masuk, bukan omzet), jaminan, checklist & foto kondisi keluar, tanda tangan
 * - perpanjang: dari jatuh tempo lama, tagihan baru
 * - kembalikan: checklist kembali → biaya kerusakan; denda telat (lewat toleransi); deposit dikembalikan setelah dipotong
 *   tagihan (kas deposit_keluar + pembayaran tunai dari deposit), sisa tagihan dibayar biasa
 */
final class PlayboxService
{
    public const SYARAT_DEFAULT = "1. Unit & seluruh kelengkapan wajib dikembalikan paling lambat pada jatuh tempo.\n"
        ."2. Keterlambatan dikenakan denda sesuai tarif yang berlaku.\n"
        ."3. Kerusakan atau kehilangan diganti sesuai harga ganti tiap kelengkapan.\n"
        ."4. Unit tidak boleh dipindahtangankan, disewakan ulang, atau dibuka segelnya.\n"
        ."5. Jaminan dikembalikan setelah unit kembali lengkap & dalam kondisi baik.";

    public function __construct(
        private NomorTransaksi $nomor,
        private ShiftService $shift,
        private KasService $kas,
    ) {}

    public static function jatuhTempo(CarbonInterface $mulai, string $satuan, int $jumlah): Carbon
    {
        $m = Carbon::parse($mulai);

        return match ($satuan) {
            'jam' => $m->copy()->addHours($jumlah),
            'hari' => $m->copy()->addDays($jumlah),
            'minggu' => $m->copy()->addWeeks($jumlah),
            'bulan' => $m->copy()->addMonthsNoOverflow($jumlah),
            default => throw new BillingException('Satuan sewa tidak dikenal.'),
        };
    }

    /**
     * Cari (HP sama) atau buat penyewa; foto & KTP (path file sementara) dikompres ke disk privat.
     *
     * @param  array{nama:string, telepon:string, nik?:?string, alamat?:?string, jenis_tempat?:?string, koordinat?:?string, catatan?:?string}  $d
     */
    public function simpanPenyewa(array $d, string $tenantId, ?string $foto = null, ?string $ktp = null, ?Penyewa $p = null): Penyewa
    {
        $telepon = Penyewa::rapikanTelepon($d['telepon'] ?? '');

        if (mb_strlen(trim((string) ($d['nama'] ?? ''))) < 2 || strlen($telepon) < 9) {
            throw new BillingException('Nama & nomor HP penyewa wajib diisi.');
        }

        $p ??= Penyewa::query()->where('telepon', $telepon)->first() ?? new Penyewa(['tenant_id' => $tenantId]);
        [$lat, $lng] = Koordinat::urai($d['koordinat'] ?? null) ?? [$p->lat, $p->lng];

        $p->fill([
            'nama' => trim($d['nama']),
            'telepon' => $telepon,
            'nik' => filled($d['nik'] ?? null) ? trim($d['nik']) : $p->nik,
            'alamat' => filled($d['alamat'] ?? null) ? trim($d['alamat']) : $p->alamat,
            'jenis_tempat' => in_array($d['jenis_tempat'] ?? '', array_keys(Penyewa::JENIS_TEMPAT), true) ? $d['jenis_tempat'] : ($p->jenis_tempat ?? 'rumah'),
            'lat' => $lat,
            'lng' => $lng,
            'catatan' => filled($d['catatan'] ?? null) ? trim($d['catatan']) : $p->catatan,
        ]);

        if ($foto) {
            $p->foto = FotoPrivat::simpan($foto, $tenantId, 'penyewa', 800);
        }

        if ($ktp) {
            $p->foto_ktp = FotoPrivat::simpan($ktp, $tenantId, 'penyewa', 1280, 72);
        }

        $p->save();

        return $p;
    }

    /**
     * @param  array{satuan:string, jumlah:int, jaminan:array, deposit?:int, checklist:array, foto_kondisi?:array<int,string>,
     *               tanda_tangan?:?string, catatan?:?string, setuju_daftar_hitam?:bool}  $d
     */
    public function sewa(Playbox $playbox, Penyewa $penyewa, User $user, array $d): SewaPlaybox
    {
        $satuan = $d['satuan'] ?? '';
        $jumlah = (int) ($d['jumlah'] ?? 0);
        $deposit = max(0, (int) ($d['deposit'] ?? 0));
        $jaminan = array_values(array_filter((array) ($d['jaminan'] ?? []), fn ($j) => isset(SewaPlaybox::JAMINAN[$j['jenis'] ?? ''])));

        if ($jumlah < 1 || $jumlah > 365) {
            throw new BillingException('Lama sewa tidak valid.');
        }

        if ($playbox->harga($satuan) <= 0) {
            throw new BillingException("{$playbox->nama} tidak disewakan per ".(Playbox::SATUAN[$satuan] ?? $satuan).'.');
        }

        if ($jaminan === [] && $deposit === 0) {
            throw new BillingException('Isi minimal satu jaminan (identitas, deposit, atau barang).');
        }

        if ($penyewa->daftar_hitam && ! (($d['setuju_daftar_hitam'] ?? false) && $user->can('shift.bantu'))) {
            throw new BillingException("{$penyewa->nama} masuk DAFTAR HITAM ({$penyewa->alasan_daftar_hitam}). Hanya supervisor/owner yang bisa tetap menyewakan.");
        }

        return DB::transaction(function () use ($playbox, $penyewa, $user, $d, $satuan, $jumlah, $deposit, $jaminan) {
            $playbox = Playbox::withoutGlobalScopes()->whereKey($playbox->id)->lockForUpdate()->firstOrFail();

            if (! $playbox->is_active || $playbox->status !== 'tersedia') {
                throw new BillingException("{$playbox->kode} sedang ".(Playbox::STATUS[$playbox->status] ?? $playbox->status).'.');
            }

            $shift = $this->shift->wajibAktif($user, $playbox->cabang_id);
            $cabang = Cabang::withoutGlobalScopes()->findOrFail($playbox->cabang_id);
            $nomor = $this->nomor->buat('SWB', $cabang);
            $mulai = now();
            $tempo = self::jatuhTempo($mulai, $satuan, $jumlah);
            $harga = $playbox->harga($satuan);

            $trx = $this->transaksiBaru($cabang, $shift->id, $user, $nomor, $penyewa->nama);
            $this->item($trx, TransaksiItem::JENIS_SEWA_LUAR, "Sewa {$playbox->kode} {$playbox->nama} · {$jumlah} ".Playbox::SATUAN[$satuan],
                $harga * $jumlah, $mulai->format('d/m H:i').' – '.$tempo->format('d/m H:i'));
            $trx->hitungUlang();

            // Foto jaminan (mis. STNK / barang) & kondisi unit saat keluar: disk privat
            foreach ($jaminan as $i => $j) {
                $jaminan[$i] = [
                    'jenis' => $j['jenis'],
                    'keterangan' => trim((string) ($j['keterangan'] ?? '')),
                    'nomor' => trim((string) ($j['nomor'] ?? '')),
                    'foto' => ! empty($j['foto']) ? FotoPrivat::simpan($j['foto'], $playbox->tenant_id, 'jaminan') : null,
                ];
            }

            $sewa = SewaPlaybox::create([
                'tenant_id' => $playbox->tenant_id,
                'cabang_id' => $playbox->cabang_id,
                'nomor' => $nomor,
                'playbox_id' => $playbox->id,
                'penyewa_id' => $penyewa->id,
                'user_id' => $user->id,
                'shift_id' => $shift->id,
                'transaksi_id' => $trx->id,
                'status' => 'berjalan',
                'satuan' => $satuan,
                'jumlah' => $jumlah,
                'harga_satuan' => $harga,
                'mulai_pada' => $mulai,
                'jatuh_tempo' => $tempo,
                'alamat' => $penyewa->alamat,
                'lat' => $penyewa->lat,
                'lng' => $penyewa->lng,
                'jaminan' => $jaminan,
                'deposit' => $deposit,
                'checklist_keluar' => $this->rapikanChecklist($d['checklist'] ?? [], $playbox),
                'foto_keluar' => array_map(fn ($f) => FotoPrivat::simpan($f, $playbox->tenant_id, 'sewa'), (array) ($d['foto_kondisi'] ?? [])),
                'tanda_tangan' => ! empty($d['tanda_tangan']) ? FotoPrivat::simpanTandaTangan($d['tanda_tangan'], $playbox->tenant_id) : null,
                'catatan' => trim((string) ($d['catatan'] ?? '')) ?: null,
            ]);

            TransaksiItem::withoutGlobalScopes()->where('transaksi_id', $trx->id)
                ->update(['referensi_type' => $sewa->getMorphClass(), 'referensi_id' => $sewa->id]);

            // Deposit = titipan (kas laci bertambah, bukan omzet)
            if ($deposit > 0) {
                $this->kas->catat($shift, 'deposit_masuk', $deposit, $user, $sewa, "Deposit sewa {$nomor} ({$penyewa->nama})");
            }

            $playbox->update(['status' => 'disewa']);

            if ($penyewa->daftar_hitam) {
                Audit::catat('sewa_daftar_hitam', "Menyewakan {$playbox->kode} ke {$penyewa->nama} (daftar hitam)", $sewa, anomali: true, userId: $user->id);
            }

            Lonceng::kirim('playbox_baru', "Sewa {$playbox->kode} · {$penyewa->nama}",
                "{$jumlah} ".Playbox::SATUAN[$satuan].' · jatuh tempo '.$sewa->jatuh_tempo->format('d/m H:i')." · oleh {$user->name}",
                subjek: $sewa, userId: $user->id);

            return $sewa;
        });
    }

    /** Perpanjang dari jatuh tempo lama; tagihan baru (bayar di muka) */
    public function perpanjang(SewaPlaybox $sewa, User $user, string $satuan, int $jumlah): Transaksi
    {
        return DB::transaction(function () use ($sewa, $user, $satuan, $jumlah) {
            $sewa = SewaPlaybox::withoutGlobalScopes()->whereKey($sewa->id)->lockForUpdate()->firstOrFail();
            $playbox = Playbox::withoutGlobalScopes()->findOrFail($sewa->playbox_id);

            if ($sewa->status !== 'berjalan') {
                throw new BillingException('Sewa sudah selesai.');
            }

            if ($jumlah < 1 || $playbox->harga($satuan) <= 0) {
                throw new BillingException('Satuan / lama perpanjangan tidak valid.');
            }

            $shift = $this->shift->wajibAktif($user, $sewa->cabang_id);
            $cabang = Cabang::withoutGlobalScopes()->findOrFail($sewa->cabang_id);
            $baru = self::jatuhTempo($sewa->jatuh_tempo, $satuan, $jumlah);
            $harga = $playbox->harga($satuan) * $jumlah;

            $trx = $this->transaksiBaru($cabang, $shift->id, $user, $this->nomor->buat('SWB', $cabang), $sewa->penyewa?->nama);
            $this->item($trx, TransaksiItem::JENIS_SEWA_LUAR, "Perpanjang {$playbox->kode} · {$jumlah} ".Playbox::SATUAN[$satuan],
                $harga, $sewa->jatuh_tempo->format('d/m H:i').' → '.$baru->format('d/m H:i'), $sewa);
            $trx->hitungUlang();

            $riwayat = (array) $sewa->perpanjangan;
            $riwayat[] = ['satuan' => $satuan, 'jumlah' => $jumlah, 'harga' => $harga, 'dari' => $sewa->jatuh_tempo->toDateTimeString(),
                'ke' => $baru->toDateTimeString(), 'transaksi_id' => $trx->id, 'oleh' => $user->name, 'pada' => now()->toDateTimeString()];
            $sewa->update(['jatuh_tempo' => $baru, 'perpanjangan' => $riwayat, 'diingatkan_pada' => null]);

            Lonceng::kirim('playbox_perpanjang', "Perpanjang {$playbox->kode} · {$sewa->penyewa?->nama}",
                "{$jumlah} ".Playbox::SATUAN[$satuan].' · jatuh tempo baru '.$baru->format('d/m H:i')." · oleh {$user->name}",
                subjek: $sewa, userId: $user->id);

            return $trx;
        });
    }

    /** @return array{menit_telat:int, denda:int} */
    public function hitungDenda(SewaPlaybox $sewa, ?CarbonInterface $kembali = null): array
    {
        $kembali = Carbon::parse($kembali ?? now());
        $telat = $kembali->gt($sewa->jatuh_tempo) ? (int) $sewa->jatuh_tempo->diffInMinutes($kembali) : 0;
        $toleransi = (int) Pengaturan::ambil('playbox.toleransi_menit', 30, $sewa->cabang_id);
        $p = $sewa->playbox ?? Playbox::withoutGlobalScopes()->find($sewa->playbox_id);

        if ($telat <= $toleransi || ! $p) {
            return ['menit_telat' => $telat, 'denda' => 0];
        }

        $denda = match (true) {
            $p->denda_jam > 0 => (int) ceil($telat / 60) * $p->denda_jam,
            $p->denda_hari > 0 => (int) ceil($telat / 1440) * $p->denda_hari,
            default => 0,
        };

        return ['menit_telat' => $telat, 'denda' => $denda];
    }

    /**
     * Terima kembali. $d: checklist [{nama, jumlah, kondisi, biaya}], foto_kondisi [path], denda (null = otomatis),
     * potong_deposit (bawaan true), catatan.
     *
     * @return array{sewa: SewaPlaybox, transaksi: ?Transaksi, deposit_kembali: int, sisa: int}
     */
    public function kembalikan(SewaPlaybox $sewa, User $user, array $d): array
    {
        return DB::transaction(function () use ($sewa, $user, $d) {
            $sewa = SewaPlaybox::withoutGlobalScopes()->with('playbox', 'penyewa')->whereKey($sewa->id)->lockForUpdate()->firstOrFail();

            if ($sewa->status !== 'berjalan') {
                throw new BillingException('Sewa ini sudah selesai.');
            }

            $kembali = now();
            $denda = array_key_exists('denda', $d) && $d['denda'] !== null ? max(0, (int) $d['denda']) : $this->hitungDenda($sewa, $kembali)['denda'];
            $checklist = array_map(fn ($c) => [
                'nama' => (string) $c['nama'],
                'jumlah' => max(0, (int) ($c['jumlah'] ?? 0)),
                'kondisi' => isset(SewaPlaybox::KONDISI[$c['kondisi'] ?? '']) ? $c['kondisi'] : 'baik',
                'biaya' => max(0, (int) ($c['biaya'] ?? 0)),
            ], (array) ($d['checklist'] ?? []));
            $kerusakan = (int) array_sum(array_column($checklist, 'biaya'));
            $tagihan = $denda + $kerusakan;
            $shift = ($tagihan > 0 || $sewa->deposit > 0) ? $this->shift->wajibAktif($user, $sewa->cabang_id) : null;
            $cabang = Cabang::withoutGlobalScopes()->findOrFail($sewa->cabang_id);

            $trx = null;

            if ($tagihan > 0) {
                $trx = $this->transaksiBaru($cabang, $shift->id, $user, $this->nomor->buat('SWB', $cabang), $sewa->penyewa?->nama);

                if ($denda > 0) {
                    $menit = $this->hitungDenda($sewa, $kembali)['menit_telat'];
                    $this->item($trx, TransaksiItem::JENIS_SEWA_LUAR, "Denda telat {$sewa->playbox->kode}", $denda,
                        'Telat '.intdiv($menit, 60).' j '.($menit % 60).' m dari '.$sewa->jatuh_tempo->format('d/m H:i'), $sewa);
                }

                foreach ($checklist as $c) {
                    if ($c['biaya'] > 0) {
                        $this->item($trx, TransaksiItem::JENIS_LAINNYA, "Ganti rugi: {$c['nama']} (".SewaPlaybox::KONDISI[$c['kondisi']].')', $c['biaya'], null, $sewa);
                    }
                }

                $trx->hitungUlang();
            }

            // Deposit kembali ke penyewa, dipotong tagihan (uang tetap di laci sebagai pembayaran tunai).
            // Tagihan > deposit: sisanya wajib dibayar bersamaan (bayar_sisa = {metode, diterima}) — pembayaran harus melunasi.
            $potong = 0;

            if ($sewa->deposit > 0) {
                $this->kas->catat($shift, 'deposit_keluar', -$sewa->deposit, $user, $sewa, "Deposit sewa {$sewa->nomor} dikembalikan");
                $potong = ($d['potong_deposit'] ?? true) && $trx ? min($sewa->deposit, $tagihan) : 0;

                if ($potong > 0) {
                    $baris = [['metode' => 'tunai', 'jumlah' => $potong, 'diterima' => $potong]];
                    $sisaBayar = $tagihan - $potong;

                    if ($sisaBayar > 0) {
                        $metode = $d['bayar_sisa']['metode'] ?? null;

                        if (! in_array($metode, ['tunai', 'qris', 'transfer'], true)) {
                            throw new BillingException('Tagihan melebihi deposit: pilih cara bayar sisa Rp '.number_format($sisaBayar, 0, ',', '.').'.');
                        }

                        $baris[] = ['metode' => $metode, 'jumlah' => $sisaBayar,
                            'diterima' => $metode === 'tunai' ? max($sisaBayar, (int) ($d['bayar_sisa']['diterima'] ?? $sisaBayar)) : null];
                    }

                    app(BillingService::class)->bayar($trx, $user, $baris);
                }
            }

            $adaRusak = collect($checklist)->contains(fn ($c) => $c['kondisi'] !== 'baik');

            $sewa->update([
                'status' => 'selesai',
                'kembali_pada' => $kembali,
                'checklist_kembali' => $checklist,
                'foto_kembali' => array_map(fn ($f) => FotoPrivat::simpan($f, $sewa->tenant_id, 'sewa'), (array) ($d['foto_kondisi'] ?? [])),
                'denda' => $denda,
                'biaya_kerusakan' => $kerusakan,
                'deposit_dipotong' => $potong,
                'transaksi_kembali_id' => $trx?->id,
                'catatan' => trim(($sewa->catatan ? $sewa->catatan."\n" : '').(string) ($d['catatan'] ?? '')) ?: null,
            ]);

            // Ada kelengkapan rusak / hilang → unit diperiksa dulu sebelum disewakan lagi
            $sewa->playbox->update(['status' => $adaRusak ? 'servis' : 'tersedia']);

            $masalah = $denda > 0 || $kerusakan > 0 || $adaRusak;
            Lonceng::kirim($masalah ? 'playbox_kembali_denda' : 'playbox_kembali', "{$sewa->playbox->kode} kembali · {$sewa->penyewa?->nama}",
                $masalah ? 'Denda Rp '.number_format($denda, 0, ',', '.').' · kerusakan Rp '.number_format($kerusakan, 0, ',', '.')
                    .($adaRusak ? ' · unit masuk servis' : '')." · oleh {$user->name}" : "Kondisi baik · oleh {$user->name}",
                subjek: $sewa, userId: $user->id);

            return [
                'sewa' => $sewa,
                'transaksi' => $trx?->fresh(),
                'deposit_kembali' => $sewa->deposit - $potong,
                'sisa' => $trx ? $trx->fresh()->sisaTagihan() : 0,
            ];
        });
    }

    /** Batal (mis. salah input / tidak jadi): tagihan dibatalkan, deposit dikembalikan, unit tersedia lagi */
    public function batal(SewaPlaybox $sewa, User $user, string $alasan): void
    {
        DB::transaction(function () use ($sewa, $user, $alasan) {
            $sewa = SewaPlaybox::withoutGlobalScopes()->whereKey($sewa->id)->lockForUpdate()->firstOrFail();

            if ($sewa->status !== 'berjalan') {
                throw new BillingException('Sewa sudah selesai.');
            }

            if ($sewa->transaksi && ! $sewa->transaksi->isDibatalkan()) {
                app(BillingService::class)->batalkan($sewa->transaksi, $user, "Batal sewa {$sewa->nomor}: {$alasan}");
            }

            if ($sewa->deposit > 0) {
                $this->kas->catat($this->shift->wajibAktif($user, $sewa->cabang_id), 'deposit_keluar', -$sewa->deposit, $user, $sewa, "Deposit sewa {$sewa->nomor} dikembalikan (batal)");
            }

            $sewa->update(['status' => 'batal', 'kembali_pada' => now(), 'catatan' => trim(($sewa->catatan ? $sewa->catatan."\n" : '').'Batal: '.$alasan)]);
            Playbox::withoutGlobalScopes()->whereKey($sewa->playbox_id)->update(['status' => 'tersedia']);

            Lonceng::kirim('playbox_batal', "Sewa {$sewa->nomor} dibatalkan", "{$alasan} · oleh {$user->name}", subjek: $sewa, userId: $user->id);
        });
    }

    /** Ringkasan sewa ke WA penyewa (bila WhatsApp aktif di cabang) */
    public function kirimRingkasan(SewaPlaybox $s): bool
    {
        if (! PengaturanNotifikasi::untuk($s->cabang_id)->waAktif() || ! $s->penyewa?->telepon) {
            return false;
        }

        $teks = "Terima kasih {$s->penyewa->nama} 🙏\nSewa {$s->playbox->kode} {$s->playbox->nama} ({$s->nomor})\n"
            ."Mulai: {$s->mulai_pada->format('d/m/Y H:i')}\nJatuh tempo: *{$s->jatuh_tempo->format('d/m/Y H:i')}*\n"
            .'Kelengkapan: '.collect($s->checklist_keluar)->map(fn ($c) => "{$c['nama']} ×{$c['jumlah']}")->implode(', ')."\n"
            .'Mohon dikembalikan tepat waktu & lengkap. Keterlambatan dikenakan denda.';

        KirimNotifikasi::antrekan('whatsapp', $s->tenant_id, $s->cabang_id, 'sewa_playbox', $teks, referensi: $s, tujuan: $s->penyewa->telepon);

        return true;
    }

    /**
     * Pengingat WA sebelum jatuh tempo (Pengaturan Operasional → Sewa Playbox → jam), sekali per jatuh tempo.
     * Dijalankan jadwal hanya di server lokal supaya tidak terkirim dua kali dari lokal & cloud.
     */
    public function kirimPengingat(): int
    {
        $n = 0;
        $sewa = SewaPlaybox::withoutGlobalScopes()->with(['penyewa', 'playbox'])
            ->where('status', 'berjalan')->whereNull('diingatkan_pada')
            ->where('jatuh_tempo', '>', now())->where('jatuh_tempo', '<=', now()->addHours(72))->get();

        foreach ($sewa as $s) {
            $jam = (int) Pengaturan::ambil('playbox.pengingat_jam', 3, $s->cabang_id);

            if ($jam <= 0 || $s->jatuh_tempo->gt(now()->addHours($jam)) || ! $s->penyewa?->telepon
                || ! PengaturanNotifikasi::untuk($s->cabang_id)->waAktif()) {
                continue;
            }

            $teks = "Halo {$s->penyewa->nama} 👋\nPengingat: sewa {$s->playbox?->kode} ({$s->nomor}) jatuh tempo "
                ."*{$s->jatuh_tempo->translatedFormat('l, d F Y \p\u\k\u\l H.i')}*.\n"
                .'Mohon dikembalikan tepat waktu, atau hubungi kami bila ingin perpanjang. Terima kasih 🙏';

            KirimNotifikasi::antrekan('whatsapp', $s->tenant_id, $s->cabang_id, 'pengingat_sewa', $teks, referensi: $s, tujuan: $s->penyewa->telepon);
            SewaPlaybox::withoutGlobalScopes()->whereKey($s->id)->update(['diingatkan_pada' => now()]);
            $n++;
        }

        return $n;
    }

    private function rapikanChecklist(array $checklist, Playbox $playbox): array
    {
        $harga = collect($playbox->daftarKelengkapan())->pluck('harga_ganti', 'nama');

        return array_values(array_map(fn ($c) => [
            'nama' => (string) $c['nama'],
            'jumlah' => max(0, (int) ($c['jumlah'] ?? 0)),
            'kondisi' => isset(SewaPlaybox::KONDISI[$c['kondisi'] ?? '']) ? $c['kondisi'] : 'baik',
            'harga_ganti' => (int) ($harga[$c['nama']] ?? $c['harga_ganti'] ?? 0),
        ], array_filter($checklist, fn ($c) => filled($c['nama'] ?? null))));
    }

    private function transaksiBaru(Cabang $cabang, string $shiftId, User $user, string $nomor, ?string $pelanggan): Transaksi
    {
        return Transaksi::create([
            'tenant_id' => $cabang->tenant_id,
            'cabang_id' => $cabang->id,
            'shift_id' => $shiftId,
            'user_id' => $user->id,
            'nomor' => $nomor,
            'jenis' => Transaksi::JENIS_SEWA_LUAR,
            'status' => Transaksi::STATUS_BELUM_BAYAR,
            'pelanggan_nama' => $pelanggan,
        ]);
    }

    private function item(Transaksi $trx, string $jenis, string $nama, int $harga, ?string $catatan = null, ?SewaPlaybox $sewa = null): void
    {
        TransaksiItem::create([
            'tenant_id' => $trx->tenant_id,
            'cabang_id' => $trx->cabang_id,
            'transaksi_id' => $trx->id,
            'jenis' => $jenis,
            'referensi_type' => $sewa?->getMorphClass(),
            'referensi_id' => $sewa?->id,
            'nama' => $nama,
            'qty' => 1,
            'harga_satuan' => $harga,
            'subtotal' => $harga,
            'catatan' => $catatan,
        ]);
    }
}
