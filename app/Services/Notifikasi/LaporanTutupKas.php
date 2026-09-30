<?php

namespace App\Services\Notifikasi;

use App\Models\Cabang;
use App\Models\Pengeluaran;
use App\Models\Shift;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\LaporanService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Menyusun laporan tutup kas: teks ringkasan (Telegram/WhatsApp) + PDF detail transaksi.
 * Harus dipanggil dengan tenancy (tenant & cabang shift) sudah aktif.
 */
final class LaporanTutupKas
{
    private const GARIS = '───────────────────────────';

    public function __construct(private LaporanService $laporan) {}

    public function data(Shift $shift): array
    {
        $dari = $shift->dibuka_pada;
        $sampai = $shift->ditutup_pada ?? now();

        $r = $this->laporan->ringkasan($dari, $sampai);
        $metode = $this->laporan->perMetode($dari, $sampai)->keyBy('metode');
        $produk = $this->laporan->produkTerlaris($dari, $sampai, 5);
        $detikMain = (int) $this->laporan->perUnit($dari, $sampai)->sum('detik');

        return [
            'shift' => $shift,
            'cabang' => Cabang::with('tenant')->find($shift->cabang_id),
            'kasir' => User::whereKey($shift->user_id)->value('name'),
            'dari' => $dari,
            'sampai' => $sampai,
            'r' => $r,
            'metode' => $metode,
            'produk' => $produk,
            'jam_main' => round($detikMain / 3600, 1),
        ];
    }

    public function teks(array $d): string
    {
        $rp = fn (int $n) => ($n < 0 ? '-Rp ' : 'Rp ').number_format(abs($n), 0, ',', '.');
        $r = $d['r'];
        $shift = $d['shift'];
        $selisih = (int) $shift->selisih;

        $baris = [
            '📊 LAPORAN TUTUP KAS',
            '🏢 '.($d['cabang']?->tenant?->nama ?? '').' · '.($d['cabang']?->nama ?? ''),
            '📅 Shift: '.$d['dari']->format('d/m/Y H:i').' – '.$d['sampai']->format('H:i').' · '.$d['kasir'],
            '⏰ Dibuat: '.now()->translatedFormat('l, d F Y \p\u\k\u\l H.i'),
            self::GARIS,
            '💰 Omzet Kotor     : '.$rp($r['omzet_kotor']),
            '🏷️ Diskon/Promo    : '.$rp($r['diskon']),
            '💵 Omzet Bersih    : '.$rp($r['omzet_bersih']),
            '🧾 Total Transaksi : '.$r['jumlah_transaksi'].' Struk',
            '📊 Rata-rata/Struk : '.$rp($r['rata_rata']),
            self::GARIS,
            '📦 RINCIAN PENDAPATAN',
            '🎮 Sewa PS         : '.$rp($r['pendapatan_sewa']),
            '🍜 F&B             : '.$rp($r['pendapatan_fnb']),
            self::GARIS,
            '💳 METODE BAYAR',
            '💵 Tunai           : '.$rp((int) ($d['metode']['tunai']->total ?? 0)),
            '📱 QRIS            : '.$rp((int) ($d['metode']['qris']->total ?? 0)),
            '🏦 Transfer        : '.$rp((int) ($d['metode']['transfer']->total ?? 0)),
            '👛 Saldo member    : '.$rp((int) ($d['metode']['saldo']->total ?? 0)),
            '➕ Top up member   : '.$rp($r['topup'] ?? 0),
            self::GARIS,
            '📈 LABA',
            '📦 HPP F&B         : '.$rp($r['hpp']),
            '📈 Laba Kotor      : '.$rp($r['laba_kotor']),
            '💸 Pengeluaran     : '.$rp($r['beban']),
            '✅ Laba Bersih     : '.$rp($r['laba_bersih']),
            self::GARIS,
            '🗄️ KAS LACI',
            'Kas Awal           : '.$rp((int) $shift->kas_awal),
            'Kas Seharusnya     : '.$rp((int) $shift->kas_seharusnya),
            'Kas Fisik          : '.$rp((int) $shift->kas_fisik),
            'Selisih            : '.$rp($selisih).($selisih === 0 ? ' ✅' : ' ⚠️'),
        ];

        if ($selisih !== 0 && $shift->catatan_tutup) {
            $baris[] = 'Keterangan         : '.$shift->catatan_tutup;
        }

        if ($d['produk']->isNotEmpty()) {
            $baris[] = self::GARIS;
            $baris[] = '🏆 PRODUK TERLARIS';

            foreach ($d['produk'] as $i => $p) {
                $baris[] = ($i + 1).'. '.$p->nama.' ('.$p->qty.' terjual)';
            }
        }

        $baris[] = self::GARIS;
        $baris[] = '⚠️ CATATAN';
        $baris[] = '❌ Transaksi Dibatalkan : '.$r['batal_jumlah'].' ('.$rp($r['batal_nilai']).')';
        $baris[] = '🕹️ Jam Main Total       : '.str_replace('.', ',', (string) $d['jam_main']).' jam';
        $baris[] = self::GARIS;
        $baris[] = 'Laporan otomatis dari '.config('app.name');

        return implode("\n", $baris);
    }

    /** Buat PDF detail, return path absolut file */
    public function pdf(array $d): string
    {
        $shift = $d['shift'];

        $transaksi = Transaksi::query()
            ->with(['unit:id,kode', 'pembayaran' => fn ($q) => $q->where('status', 'sukses')])
            ->where('is_latihan', false)
            ->where(function ($q) use ($d) {
                $q->whereBetween('dibayar_pada', [$d['dari'], $d['sampai']])
                    ->orWhereBetween('dibatalkan_pada', [$d['dari'], $d['sampai']]);
            })
            ->orderBy('created_at')
            ->get();

        $pengeluaran = Pengeluaran::query()
            ->whereBetween('created_at', [$d['dari'], $d['sampai']])
            ->orderBy('created_at')
            ->get();

        $isi = Pdf::loadView('pdf.laporan-shift', $d + [
            'transaksi' => $transaksi,
            'pengeluaran' => $pengeluaran,
        ])->setPaper('a4')->output();

        $path = 'laporan/'.$shift->tenant_id.'/'.$this->namaFile($d);
        Storage::disk('local')->put($path, $isi);

        return Storage::disk('local')->path($path);
    }

    public function namaFile(array $d): string
    {
        return 'Laporan-'.($d['cabang']?->kode ?? 'CBG').'-'.$d['dari']->format('Ymd-Hi').'.pdf';
    }
}
