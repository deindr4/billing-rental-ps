@php
    $r = $p->rincian ?? [];
    $h = $r['hadir'] ?? [];
    $t = $r['tarif'] ?? [];
    $rp = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Slip gaji {{ $p->karyawan?->nama }} · {{ $p->nomor }}</title>
    <style>
        body { font: 14px/1.5 system-ui, sans-serif; color: #111; background: #fff; margin: 0; }
        .slip { max-width: 560px; margin: 24px auto; padding: 24px; border: 1px solid #ddd; border-radius: 8px; }
        h1 { font-size: 18px; margin: 0 0 4px; } .muted { color: #666; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        td { padding: 6px 0; border-bottom: 1px solid #eee; } td:last-child { text-align: right; white-space: nowrap; }
        .total td { font-weight: 700; font-size: 16px; border-bottom: 0; padding-top: 10px; }
        .ttd { display: flex; justify-content: space-between; margin-top: 40px; font-size: 12px; text-align: center; }
        .ttd div { width: 45%; } .garis { margin-top: 48px; border-top: 1px solid #999; }
        @media print { .slip { border: 0; margin: 0; } .cetak { display: none; } }
    </style>
</head>
<body>
<div class="slip">
    <button class="cetak" onclick="window.print()" style="float:right">Cetak</button>
    <h1>Slip Gaji</h1>
    <div class="muted">{{ $tenant }} · {{ $p->nomor }} · {{ \App\Models\Penggajian::STATUS[$p->status] ?? $p->status }}</div>

    <table>
        <tr><td>Nama</td><td>{{ $p->karyawan?->nama }}{{ $p->karyawan?->jabatan ? ' · '.$p->karyawan->jabatan : '' }}</td></tr>
        <tr><td>Periode</td><td>{{ $p->labelPeriode() }}</td></tr>
        <tr><td>Kehadiran</td><td>{{ $h['hari_hadir'] ?? 0 }} hari · {{ intdiv((int) ($h['menit_kerja'] ?? 0), 60) }} jam · terlambat {{ $h['terlambat_kali'] ?? 0 }}×</td></tr>
    </table>

    <table>
        <tr><td>Gaji pokok{{ ($r['pokok_prorata'] ?? false) ? ' (prorata)' : '' }}</td><td>{{ $rp($p->gaji_pokok) }}</td></tr>
        <tr><td>Upah hadir ({{ $h['hari_hadir'] ?? 0 }} × {{ $rp($t['upah_shift'] ?? 0) }})</td><td>{{ $rp($p->upah_hadir) }}</td></tr>
        <tr><td>Upah jam kerja</td><td>{{ $rp($p->upah_jam) }}</td></tr>
        <tr><td>Bonus target omzet</td><td>{{ $rp($p->bonus) }}</td></tr>
        @foreach ($r['penyesuaian'] ?? [] as $s)
            <tr><td>{{ $s['keterangan'] }}</td><td>{{ $s['nilai'] < 0 ? '− '.$rp(-$s['nilai']) : $rp($s['nilai']) }}</td></tr>
        @endforeach
        @foreach (collect($r['usulan_potongan'] ?? [])->where('disetujui', true) as $u)
            <tr><td>Potongan: {{ $u['jenis'] }} ({{ $u['tanggal'] }})</td><td>− {{ $rp($u['nilai']) }}</td></tr>
        @endforeach
        <tr class="total"><td>Total diterima</td><td>{{ $rp($p->total) }}</td></tr>
    </table>

    @if ($p->catatan)
        <p class="muted">Catatan: {{ $p->catatan }}</p>
    @endif

    <div class="ttd">
        <div>Penerima<div class="garis">{{ $p->karyawan?->nama }}</div></div>
        <div>Pemilik<div class="garis">&nbsp;</div></div>
    </div>
</div>
</body>
</html>
