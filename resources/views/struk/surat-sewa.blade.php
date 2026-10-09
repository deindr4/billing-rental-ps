@php
    /** @var \App\Models\SewaPlaybox $s */
    $p = $s->penyewa;
    $rp = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
    $nik = $p?->nik ? substr($p->nik, 0, 4).str_repeat('•', max(0, strlen($p->nik) - 8)).substr($p->nik, -4) : '-';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Surat sewa {{ $s->nomor }}</title>
    <style>
        body { font: 13px/1.5 system-ui, sans-serif; color: #111; background: #fff; margin: 0; }
        .kertas { max-width: 680px; margin: 20px auto; padding: 24px; border: 1px solid #ddd; border-radius: 8px; }
        h1 { font-size: 18px; margin: 0; } .muted { color: #666; font-size: 12px; }
        h2 { font-size: 13px; margin: 16px 0 6px; text-transform: uppercase; letter-spacing: .04em; color: #444; }
        table { width: 100%; border-collapse: collapse; } td, th { padding: 4px 6px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
        .kanan { text-align: right; } .syarat { white-space: pre-line; font-size: 12px; }
        .ttd { display: flex; justify-content: space-between; margin-top: 24px; text-align: center; font-size: 12px; }
        .ttd div { width: 45%; } .ttd img { height: 70px; } .garis { border-top: 1px solid #999; margin-top: 4px; padding-top: 2px; }
        @media print { .kertas { border: 0; margin: 0; } .tombol { display: none; } }
    </style>
</head>
<body>
<div class="kertas">
    <div class="tombol" style="float:right; display:flex; gap:6px">
        <button onclick="window.print()">Cetak</button>
        @if ($p?->telepon)<a href="{{ $wa }}" target="_blank" rel="noopener"><button>Kirim WA</button></a>@endif
    </div>
    <h1>Surat Sewa Playbox</h1>
    <div class="muted">{{ $tenant }} · {{ $s->nomor }} · {{ $s->mulai_pada->format('d/m/Y H:i') }}</div>

    <h2>Penyewa</h2>
    <table>
        <tr><td style="width:35%">Nama</td><td>{{ $p?->nama }}</td></tr>
        <tr><td>No. HP</td><td>{{ $p?->telepon }}</td></tr>
        <tr><td>NIK</td><td>{{ $nik }}</td></tr>
        <tr><td>Alamat ({{ \App\Models\Penyewa::JENIS_TEMPAT[$p?->jenis_tempat] ?? '' }})</td>
            <td>{{ $s->alamat }}@if ($s->urlMaps()) · <a href="{{ $s->urlMaps() }}">lokasi Google Maps</a>@endif</td></tr>
    </table>

    <h2>Barang & sewa</h2>
    <table>
        <tr><td style="width:35%">Unit</td><td>{{ $s->playbox?->kode }} · {{ $s->playbox?->nama }}{{ $s->playbox?->nomor_seri ? ' · SN '.$s->playbox->nomor_seri : '' }}</td></tr>
        <tr><td>Lama sewa</td><td>{{ $s->labelDurasi() }} × {{ $rp($s->harga_satuan) }}</td></tr>
        <tr><td>Mulai – jatuh tempo</td><td><b>{{ $s->mulai_pada->format('d/m/Y H:i') }} – {{ $s->jatuh_tempo->format('d/m/Y H:i') }}</b></td></tr>
        @foreach ((array) $s->perpanjangan as $x)
            <tr><td>Perpanjang</td><td>{{ $x['jumlah'] }} {{ \App\Models\Playbox::SATUAN[$x['satuan']] ?? '' }} · {{ $rp($x['harga']) }} → {{ \Illuminate\Support\Carbon::parse($x['ke'])->format('d/m/Y H:i') }}</td></tr>
        @endforeach
        <tr><td>Denda telat</td><td>{{ $s->playbox?->denda_jam ? $rp($s->playbox->denda_jam).' / jam' : ($s->playbox?->denda_hari ? $rp($s->playbox->denda_hari).' / hari' : '-') }}</td></tr>
    </table>

    <h2>Jaminan</h2>
    <table>
        @forelse ((array) $s->jaminan as $j)
            <tr><td style="width:35%">{{ \App\Models\SewaPlaybox::JAMINAN[$j['jenis']] ?? $j['jenis'] }}</td><td>{{ $j['keterangan'] }}{{ ! empty($j['nomor']) ? ' · '.$j['nomor'] : '' }}</td></tr>
        @empty
            <tr><td colspan="2">-</td></tr>
        @endforelse
        @if ($s->deposit)<tr><td>Uang deposit</td><td>{{ $rp($s->deposit) }}</td></tr>@endif
    </table>

    <h2>Kondisi & kelengkapan saat keluar</h2>
    <table>
        <tr><th>Barang</th><th class="kanan">Jumlah</th><th>Kondisi</th><th class="kanan">Harga ganti</th></tr>
        @foreach ((array) $s->checklist_keluar as $c)
            <tr><td>{{ $c['nama'] }}</td><td class="kanan">{{ $c['jumlah'] }}</td><td>{{ \App\Models\SewaPlaybox::KONDISI[$c['kondisi']] ?? $c['kondisi'] }}</td><td class="kanan">{{ ($c['harga_ganti'] ?? 0) ? $rp($c['harga_ganti']) : '-' }}</td></tr>
        @endforeach
    </table>

    @if ($s->status === 'selesai')
        <h2>Pengembalian · {{ $s->kembali_pada?->format('d/m/Y H:i') }}</h2>
        <table>
            @foreach ((array) $s->checklist_kembali as $c)
                <tr><td>{{ $c['nama'] }}</td><td class="kanan">{{ $c['jumlah'] }}</td><td>{{ \App\Models\SewaPlaybox::KONDISI[$c['kondisi']] ?? '' }}</td><td class="kanan">{{ $c['biaya'] ? $rp($c['biaya']) : '-' }}</td></tr>
            @endforeach
            <tr><td colspan="3">Denda telat</td><td class="kanan">{{ $rp($s->denda) }}</td></tr>
            @if ($s->deposit)<tr><td colspan="3">Deposit dikembalikan</td><td class="kanan">{{ $rp($s->deposit - $s->deposit_dipotong) }}</td></tr>@endif
        </table>
    @endif

    <h2>Syarat & ketentuan</h2>
    <div class="syarat">{{ $syarat }}</div>

    <div class="ttd">
        <div>Penyewa
            @if ($s->tanda_tangan)<div><img src="{{ \App\Support\FotoPrivat::url($s->tanda_tangan) }}" alt="tanda tangan"></div>@else<div style="height:70px"></div>@endif
            <div class="garis">{{ $p?->nama }}</div>
        </div>
        <div>Petugas<div style="height:70px"></div><div class="garis">{{ $petugas }}</div></div>
    </div>
</div>
</body>
</html>
