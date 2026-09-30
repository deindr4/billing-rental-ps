@php
    $rp = fn ($n) => ((int) $n < 0 ? '-Rp ' : 'Rp ').number_format(abs((int) $n), 0, ',', '.');
    $metodeLabel = ['tunai' => 'Tunai', 'qris' => 'QRIS', 'transfer' => 'Transfer', 'saldo' => 'Saldo'];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Tutup Kas</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #0f1c2b; margin: 0; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        h2 { font-size: 12px; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #d9e1ea; }
        .muted { color: #5b6b80; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 9px; color: #5b6b80; text-transform: uppercase; border-bottom: 1px solid #d9e1ea; padding: 4px; }
        td { padding: 4px; border-bottom: 1px solid #eef2f6; vertical-align: top; }
        .r { text-align: right; }
        .b { font-weight: bold; }
        .batal { color: #dc2626; text-decoration: line-through; }
        .grid td { border: none; padding: 2px 4px; }
        .kotak { width: 49%; display: inline-block; vertical-align: top; }
    </style>
</head>
<body>
    <h1>Laporan Tutup Kas</h1>
    <div class="muted">
        {{ $cabang?->tenant?->nama }} · {{ $cabang?->nama }} · Kasir {{ $kasir }}<br>
        Shift {{ $dari->format('d/m/Y H:i') }} – {{ $sampai->format('d/m/Y H:i') }} · {{ $shift->nomor }}<br>
        Dibuat {{ now()->format('d/m/Y H:i') }}
    </div>

    <div class="kotak">
        <h2>Ringkasan</h2>
        <table class="grid">
            <tr><td>Sewa PS</td><td class="r">{{ $rp($r['pendapatan_sewa']) }}</td></tr>
            <tr><td>F&amp;B</td><td class="r">{{ $rp($r['pendapatan_fnb']) }}</td></tr>
            <tr><td>Omzet kotor</td><td class="r">{{ $rp($r['omzet_kotor']) }}</td></tr>
            <tr><td>Diskon</td><td class="r">{{ $rp(-$r['diskon']) }}</td></tr>
            <tr class="b"><td>Omzet bersih</td><td class="r">{{ $rp($r['omzet_bersih']) }}</td></tr>
            <tr><td>HPP F&amp;B</td><td class="r">{{ $rp(-$r['hpp']) }}</td></tr>
            <tr><td>Pengeluaran</td><td class="r">{{ $rp(-$r['beban']) }}</td></tr>
            <tr class="b"><td>Laba bersih</td><td class="r">{{ $rp($r['laba_bersih']) }}</td></tr>
            <tr><td>Transaksi</td><td class="r">{{ $r['jumlah_transaksi'] }}</td></tr>
            <tr><td>Dibatalkan</td><td class="r">{{ $r['batal_jumlah'] }} ({{ $rp($r['batal_nilai']) }})</td></tr>
        </table>
    </div>
    <div class="kotak" style="margin-left: 2%;">
        <h2>Kas laci</h2>
        <table class="grid">
            <tr><td>Kas awal</td><td class="r">{{ $rp($shift->kas_awal) }}</td></tr>
            <tr><td>Kas seharusnya</td><td class="r">{{ $rp($shift->kas_seharusnya) }}</td></tr>
            <tr><td>Kas fisik</td><td class="r">{{ $rp($shift->kas_fisik) }}</td></tr>
            <tr class="b"><td>Selisih</td><td class="r">{{ $rp($shift->selisih) }}</td></tr>
            @if ($shift->catatan_tutup)
                <tr><td colspan="2" class="muted">{{ $shift->catatan_tutup }}</td></tr>
            @endif
        </table>
        <h2>Metode bayar</h2>
        <table class="grid">
            @forelse ($metode as $m)
                <tr><td>{{ $metodeLabel[$m->metode] ?? $m->metode }} ({{ $m->jumlah }})</td><td class="r">{{ $rp($m->total) }}</td></tr>
            @empty
                <tr><td class="muted">Tidak ada pembayaran</td></tr>
            @endforelse
        </table>
    </div>

    <h2>Detail transaksi</h2>
    <table>
        <thead>
            <tr>
                <th>Nomor</th><th>Jam</th><th>Unit</th><th>Pelanggan</th><th>Bayar</th><th class="r">Total</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($transaksi as $t)
                <tr>
                    <td>{{ $t->nomor }}</td>
                    <td>{{ ($t->dibayar_pada ?? $t->created_at)->format('H:i') }}</td>
                    <td>{{ $t->unit?->kode ?? strtoupper($t->jenis) }}</td>
                    <td>{{ $t->pelanggan_nama ?: 'Tamu' }}</td>
                    <td>{{ $t->pembayaran->map(fn ($p) => $metodeLabel[$p->metode] ?? $p->metode)->unique()->implode(', ') ?: '-' }}</td>
                    <td class="r {{ $t->isDibatalkan() ? 'batal' : '' }}">{{ $rp($t->total) }}</td>
                    <td>{{ $t->isDibatalkan() ? 'Batal: '.$t->alasan_batal : 'Lunas' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Tidak ada transaksi.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Pengeluaran</h2>
    <table>
        <thead>
            <tr><th>Nomor</th><th>Jam</th><th>Kategori</th><th>Keterangan</th><th>Sumber</th><th class="r">Jumlah</th></tr>
        </thead>
        <tbody>
            @forelse ($pengeluaran as $p)
                <tr>
                    <td>{{ $p->nomor }}</td>
                    <td>{{ $p->created_at->format('H:i') }}</td>
                    <td>{{ \App\Models\Pengeluaran::KATEGORI[$p->kategori] ?? $p->kategori }}</td>
                    <td>{{ $p->keterangan }}</td>
                    <td>{{ \App\Models\Pengeluaran::SUMBER_DANA[$p->sumber_dana] ?? $p->sumber_dana }}</td>
                    <td class="r {{ $p->isDibatalkan() ? 'batal' : '' }}">{{ $rp($p->jumlah) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Tidak ada pengeluaran.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
