<?php

namespace App\Services\Struk;

use App\Models\Cabang;
use App\Models\MemberMutasi;
use App\Models\Pembayaran;
use App\Models\Pengaturan;
use App\Models\Transaksi;
use App\Models\TransaksiItem;
use App\Services\Billing\BillingService;
use App\Services\Member\PengaturanMember;
use App\Support\EscPos;
use Illuminate\Support\Collection;

/**
 * Menyusun isi struk sekali, lalu dirender ke:
 * - ESC/POS (printer thermal Bluetooth lewat aplikasi RawBT)
 * - teks monospace (struk digital WhatsApp)
 * Nota A4 memakai data yang sama lewat view `struk.nota`.
 */
final class StrukService
{
    public const LEBAR = [58 => 32, 80 => 48]; // mm => kolom karakter

    public const METODE = Pembayaran::LABEL;

    public const FOOTER_DEFAULT = 'Terima kasih, selamat bermain!';

    /** Pengaturan struk cabang */
    public function setelan(string $cabangId): array
    {
        $lebar = (int) Pengaturan::ambil('struk.lebar', 58, $cabangId);

        return [
            'lebar' => isset(self::LEBAR[$lebar]) ? $lebar : 58,
            'header' => (string) Pengaturan::ambil('struk.header', '', $cabangId),
            'footer' => (string) Pengaturan::ambil('struk.footer', self::FOOTER_DEFAULT, $cabangId),
        ];
    }

    /** Data lengkap transaksi untuk struk / nota */
    public function data(Transaksi $transaksi): array
    {
        $transaksi->loadMissing([
            'items',
            'diskon',
            'pembayaran' => fn ($q) => $q->where('status', 'sukses')->orderBy('dibayar_pada'),
            'unit:id,nama',
            'user:id,name',
            'member',
        ]);

        $cabang = Cabang::with('tenant')->find($transaksi->cabang_id);

        // Open billing masih berjalan: sewa belum jadi item, cetak sebagai tagihan sementara dengan perkiraan
        $sesi = $transaksi->sesi()->withoutGlobalScopes()->first();
        $estimasi = $sesi ? app(BillingService::class)->estimasiSewaOpen($sesi) : null;

        // Bayar gabungan antar unit: semua tagihan dalam grup dicetak dalam satu struk
        $grup = $transaksi->grup_bayar
            ? Transaksi::withoutGlobalScopes()->where('tenant_id', $transaksi->tenant_id)->where('grup_bayar', $transaksi->grup_bayar)
                ->with(['items', 'diskon', 'unit:id,nama', 'pembayaran' => fn ($q) => $q->where('status', 'sukses')->orderBy('dibayar_pada')])
                ->orderBy('dibayar_pada')->orderBy('nomor')->get()
            : null;

        return [
            'trx' => $transaksi,
            'cabang' => $cabang,
            'setelan' => $this->setelan($transaksi->cabang_id),
            'estimasi_sewa' => $estimasi,
            'sisa' => $transaksi->isDibatalkan() ? 0 : $transaksi->sisaTagihan() + (int) $estimasi,
            'diskon' => $transaksi->diskon->where('nilai', '>', 0)->values(),
            'member' => $this->infoMember($transaksi),
            'grup' => $grup && $grup->count() > 1 ? $grup : null,
        ];
    }

    /** Saldo & poin member setelah transaksi ini (untuk kaki struk) */
    private function infoMember(Transaksi $transaksi): ?array
    {
        $m = $transaksi->member;

        if (! $m) {
            return null;
        }

        $poinDapat = (int) MemberMutasi::withoutGlobalScopes()
            ->where('transaksi_id', $transaksi->id)
            ->where('akun', MemberMutasi::AKUN_POIN)
            ->whereIn('jenis', ['dapat', 'batal'])
            ->sum('jumlah');

        return [
            'nama' => $m->nama,
            'kode' => $m->kode,
            'tier' => $m->tier,
            'saldo' => (int) $m->saldo,
            'poin' => (int) $m->poin,
            'poin_dapat' => $poinDapat,
            'stamp' => (int) $m->stamp,
            'target_stamp' => app(PengaturanMember::class)->targetStamp(),
        ];
    }

    /**
     * Baris struk untuk lebar kolom tertentu.
     *
     * @return array<int, array{t:string, rata:string, tebal:bool, besar:bool}>
     */
    public function baris(array $d, int $kolom): array
    {
        $trx = $d['trx'];
        $cabang = $d['cabang'];
        $rp = fn (int $n) => ($n < 0 ? '-' : '').number_format(abs($n), 0, ',', '.');
        $garis = str_repeat('-', $kolom);
        $b = [];

        $tambah = function (string $t, string $rata = 'kiri', bool $tebal = false, bool $besar = false) use (&$b) {
            $b[] = compact('t', 'rata', 'tebal', 'besar');
        };

        $kk = function (string $kiri, string $kanan, bool $tebal = false) use ($tambah, $kolom) {
            foreach ($this->kiriKanan($kiri, $kanan, $kolom) as $t) {
                $tambah($t, 'kiri', $tebal);
            }
        };

        // Kepala
        $nama = EscPos::ascii($cabang?->tenant?->nama ?? config('app.name'));
        $muatBesar = mb_strlen($nama) <= intdiv($kolom, 2);

        foreach ($this->bungkus($nama, $muatBesar ? intdiv($kolom, 2) : $kolom) as $t) {
            $tambah($t, 'tengah', true, $muatBesar);
        }

        if ($cabang?->nama && $cabang->nama !== $cabang->tenant?->nama) {
            $tambah($cabang->nama, 'tengah');
        }

        foreach (array_filter([$cabang?->alamat, $cabang?->telepon ? 'Telp '.$cabang->telepon : null, $d['setelan']['header']]) as $info) {
            foreach (preg_split('/\R/', (string) $info) as $bagian) {
                foreach ($this->bungkus($bagian, $kolom) as $t) {
                    $tambah($t, 'tengah');
                }
            }
        }

        $tambah($garis);

        if ($grup = $d['grup'] ?? null) {
            $this->barisGabungan($d, $grup, $kolom, $tambah, $kk, $rp, $garis); // menulis ke $b lewat $tambah

            return $b;
        }

        $kk('No', $trx->nomor);
        $kk('Tanggal', ($trx->dibayar_pada ?? $trx->created_at)->format('d/m/Y H:i'));
        $kk('Kasir', (string) $trx->user?->name);

        if ($trx->unit) {
            $kk('Unit', $trx->unit->nama);
        }

        if ($d['member']) {
            $kk('Member', "{$d['member']['kode']} {$d['member']['tier']}");
            $kk('Nama', $d['member']['nama']);
        } elseif ($trx->pelanggan_nama) {
            $kk('Pelanggan', $trx->pelanggan_nama);
        }

        $tambah($garis);

        // Item
        foreach ($trx->items as $item) {
            if ($item->qty > 1) {
                foreach ($this->bungkus($item->nama, $kolom) as $t) {
                    $tambah($t);
                }
                $kk("  {$item->qty} x ".$rp($item->harga_satuan), $rp($item->subtotal));
            } else {
                $kk($item->nama, $rp($item->subtotal));
            }

            // Jam masuk/keluar & durasi sewa (catatan lain bersifat internal)
            if ($item->jenis === TransaksiItem::JENIS_SEWA && $item->catatan) {
                foreach (explode(' | ', $item->catatan) as $info) {
                    foreach ($this->bungkus('  '.$info, $kolom) as $t) {
                        $tambah($t);
                    }
                }
            }
        }

        if (($d['estimasi_sewa'] ?? null) !== null) {
            $kk('Sewa berjalan (perkiraan)', $rp($d['estimasi_sewa']));
            $tambah('  s.d. '.now()->format('H:i').' - final saat selesai');
        }

        foreach ($d['diskon'] as $diskon) {
            $kk($diskon->nama, $rp(-$diskon->nilai));
        }

        // Total
        $tambah($garis);

        if ($trx->total_diskon > 0) {
            $kk('Subtotal', $rp($trx->subtotal));
            $kk('Diskon', $rp(-$trx->total_diskon));
        }

        if (($d['estimasi_sewa'] ?? null) !== null) {
            $kk('TOTAL SEMENTARA', 'Rp '.$rp($trx->total + $d['estimasi_sewa']), true);
        } else {
            $kk('TOTAL', 'Rp '.$rp($trx->total), true);
        }

        foreach ($trx->pembayaran as $p) {
            $kk(self::METODE[$p->metode] ?? $p->metode, $rp($p->jumlah));

            if ($p->metode === 'tunai' && $p->diterima > $p->jumlah) {
                $kk('  Diterima', $rp($p->diterima));
            }
        }

        if ($trx->kembalian > 0) {
            $kk('Kembalian', $rp($trx->kembalian), true);
        }

        if ($trx->isDibatalkan()) {
            $tambah('');
            $tambah('*** DIBATALKAN ***', 'tengah', true);
        } elseif ($d['sisa'] > 0) {
            $tambah('');
            $kk('BELUM LUNAS - Sisa', 'Rp '.$rp($d['sisa']), true);
        }

        // Info member
        if (($m = $d['member']) && ! $trx->isDibatalkan()) {
            $tambah($garis);

            if ($m['poin_dapat'] > 0) {
                $kk('Poin didapat', '+'.$rp($m['poin_dapat']));
            }

            $kk('Total poin', $rp($m['poin']));

            if ($m['target_stamp'] > 0) {
                $kk('Stamp', $m['stamp'].'/'.$m['target_stamp']);
            }

            $kk('Sisa saldo', 'Rp '.$rp($m['saldo']));
        }

        // Kaki
        $tambah($garis);

        foreach (preg_split('/\R/', $d['setelan']['footer']) as $bagian) {
            foreach ($this->bungkus($bagian, $kolom) as $t) {
                $tambah($t, 'tengah');
            }
        }

        $tambah('Dicetak '.now()->format('d/m/Y H:i'), 'tengah');

        return $b;
    }

    /**
     * Struk bayar gabungan: tiap tagihan (unit / POS) dengan item & subtotalnya, lalu total & pembayaran gabungan.
     *
     * @param  Collection<int, Transaksi>  $grup
     */
    private function barisGabungan(array $d, Collection $grup, int $kolom, callable $tambah, callable $kk, callable $rp, string $garis): void
    {
        $trx = $d['trx'];
        $kk('Bayar gabungan', $grup->count().' tagihan');
        $kk('Tanggal', ($trx->dibayar_pada ?? $trx->created_at)->format('d/m/Y H:i'));
        $kk('Kasir', (string) $trx->user?->name);

        foreach ($grup as $t) {
            $tambah($garis);
            $tambah(($t->unit?->nama ?? 'POS / F&B').' · '.$t->nomor, 'kiri', true);

            foreach ($t->items->where('qty', '>', 0) as $item) {
                if ($item->qty > 1) {
                    foreach ($this->bungkus($item->nama, $kolom) as $baris) {
                        $tambah($baris);
                    }
                    $kk("  {$item->qty} x ".$rp($item->harga_satuan), $rp($item->subtotal));
                } else {
                    $kk($item->nama, $rp($item->subtotal));
                }
            }

            foreach ($t->diskon->where('nilai', '>', 0) as $diskon) {
                $kk($diskon->nama, $rp(-$diskon->nilai));
            }

            $kk('Subtotal', $rp($t->total));
        }

        $tambah($garis);
        $kk('TOTAL', 'Rp '.$rp((int) $grup->sum('total')), true);

        // Pembayaran digabung per metode (dibagi ke tiap tagihan saat dicatat)
        $semua = $grup->flatMap->pembayaran;

        foreach ($semua->groupBy('metode') as $metode => $daftar) {
            $kk(self::METODE[$metode] ?? $metode, $rp((int) $daftar->sum('jumlah')));

            if ($metode === 'tunai' && (int) $daftar->sum('diterima') > (int) $daftar->sum('jumlah')) {
                $kk('  Diterima', $rp((int) $daftar->sum('diterima')));
            }
        }

        if (($kembali = (int) $grup->sum('kembalian')) > 0) {
            $kk('Kembalian', $rp($kembali), true);
        }

        $tambah($garis);

        foreach (preg_split('/\R/', $d['setelan']['footer']) as $bagian) {
            foreach ($this->bungkus($bagian, $kolom) as $baris) {
                $tambah($baris, 'tengah');
            }
        }

        $tambah('Dicetak '.now()->format('d/m/Y H:i'), 'tengah');
    }

    /** Perintah ESC/POS siap kirim ke printer */
    public function escpos(Transaksi $transaksi): string
    {
        $d = $this->data($transaksi);
        $pos = new EscPos;

        foreach ($this->baris($d, self::LEBAR[$d['setelan']['lebar']]) as $r) {
            $pos->rata($r['rata'])->tebal($r['tebal'])->besar($r['besar'])->baris($r['t']);
        }

        return $pos->tebal(false)->besar(false)->maju(4)->potong()->hasil();
    }

    /** Struk digital untuk WhatsApp (monospace) */
    public function teksWa(Transaksi $transaksi): string
    {
        $kolom = 32;

        $isi = collect($this->baris($this->data($transaksi), $kolom))
            ->map(fn ($r) => $r['rata'] === 'tengah'
                ? str_repeat(' ', max(0, intdiv($kolom - mb_strlen($r['t']), 2))).$r['t']
                : $r['t'])
            ->map(fn ($t) => rtrim($t))
            ->implode("\n");

        return "```\n{$isi}\n```";
    }

    /** Intent Android untuk aplikasi RawBT (printer Bluetooth) */
    public function urlRawbt(Transaksi $transaksi): string
    {
        return 'intent:base64,'.base64_encode($this->escpos($transaksi))
            .'#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end;';
    }

    /* ---------------- Helper tata letak ---------------- */

    /** @return array<int,string> */
    private function bungkus(string $teks, int $kolom): array
    {
        $teks = rtrim(EscPos::ascii($teks));
        $indent = str_repeat(' ', strlen($teks) - strlen(ltrim($teks)));
        $teks = ltrim($teks);

        if ($teks === '') {
            return [];
        }

        // Indentasi awal dipertahankan untuk semua baris hasil bungkus
        return array_map(
            fn ($t) => $indent.$t,
            explode("\n", wordwrap($teks, max(1, $kolom - strlen($indent)), "\n", true))
        );
    }

    /**
     * Teks kiri & kanan dalam satu baris; jika tidak muat, teks kiri dibungkus
     * dan nilai kanan ditaruh di baris terakhir.
     *
     * @return array<int,string>
     */
    private function kiriKanan(string $kiri, string $kanan, int $kolom): array
    {
        $kiri = EscPos::ascii($kiri);
        $kanan = EscPos::ascii($kanan);
        $ruang = $kolom - mb_strlen($kanan) - 1;

        if ($ruang < 4) {
            return [...$this->bungkus($kiri, $kolom), str_pad($kanan, $kolom, ' ', STR_PAD_LEFT)];
        }

        $potongan = explode("\n", wordwrap($kiri, $ruang, "\n", true));
        $akhir = array_pop($potongan);

        return [...$potongan, str_pad($akhir, $kolom - mb_strlen($kanan)).$kanan];
    }
}
