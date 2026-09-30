@php
    $rp = fn ($n) => ((int) $n < 0 ? '-Rp ' : 'Rp ').number_format(abs((int) $n), 0, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Penyusutan Aset</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 9px; color: #0f1c2b; margin: 0; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        h2 { font-size: 12px; margin: 14px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #d9e1ea; }
        .muted { color: #5b6b80; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 8px; color: #5b6b80; text-transform: uppercase; border-bottom: 1px solid #d9e1ea; padding: 4px; }
        td { padding: 4px; border-bottom: 1px solid #eef2f6; vertical-align: top; }
        .r { text-align: right; }
        .b { font-weight: bold; }
        .lepas { color: #5b6b80; }
        tfoot td { font-weight: bold; border-top: 1px solid #0f1c2b; }
    </style>
</head>
<body>
    <h1>Laporan Penyusutan Aset</h1>
    <div class="muted">
        {{ $cabang?->tenant?->nama }} · {{ $cabang?->nama }} · Metode garis lurus<br>
        Per {{ now()->format('d/m/Y H:i') }}
    </div>

    <h2>Ringkasan</h2>
    <table style="width: 60%">
        <tr><td>Total investasi (aset dimiliki)</td><td class="r b">{{ $rp($r['investasi']) }}</td></tr>
        <tr><td>Nilai buku</td><td class="r">{{ $rp($r['nilai_buku']) }}</td></tr>
        <tr><td>Akumulasi penyusutan</td><td class="r">{{ $rp($r['investasi'] - $r['nilai_buku']) }} ({{ $r['persen_susut'] }}%)</td></tr>
        @if ($lihatLaba)
            <tr><td>Akumulasi laba bersih</td><td class="r">{{ $rp($r['laba']) }}</td></tr>
            <tr><td>ROI</td><td class="r">{{ number_format($r['roi'], 1, ',', '.') }}%</td></tr>
        @endif
        <tr><td>Suntikan modal · Prive owner</td><td class="r">{{ $rp($r['modal_masuk']) }} · {{ $rp($r['prive']) }}</td></tr>
    </table>

    <h2>Rincian aset</h2>
    <table>
        <thead>
            <tr>
                <th>Aset</th><th>Kategori</th><th>Tgl beli</th><th class="r">Harga</th><th class="r">Umur</th>
                <th class="r">Susut/bln</th><th class="r">Akumulasi</th><th class="r">Nilai buku</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($baris as $b)
                @php $a = $b['aset']; @endphp
                <tr class="{{ $a->status === 'dilepas' ? 'lepas' : '' }}">
                    <td>{{ $a->nama }}@if ($a->unit)<br><span class="muted">{{ $a->unit->nama }}</span>@endif @if ($a->serial)<br><span class="muted">S/N {{ $a->serial }}</span>@endif</td>
                    <td>{{ \App\Models\Aset::KATEGORI[$a->kategori] ?? $a->kategori }}</td>
                    <td>{{ $a->tanggal_beli->format('d/m/Y') }}</td>
                    <td class="r">{{ $rp($a->harga_perolehan) }}</td>
                    <td class="r">{{ $a->umur_bulan }} bln</td>
                    <td class="r">{{ $rp($b['per_bulan']) }}</td>
                    <td class="r">{{ $rp($b['akumulasi']) }}</td>
                    <td class="r">{{ $rp($b['nilai_buku']) }}</td>
                    <td>{{ \App\Models\Aset::STATUS[$a->status] }}@if ($a->dilepas_pada)<br><span class="muted">{{ $a->dilepas_pada->format('d/m/Y') }}{{ $a->nilai_lepas !== null ? ' · '.$rp($a->nilai_lepas) : '' }}</span>@endif</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            @php $aktif = $baris->filter(fn ($b) => $b['aset']->status !== 'dilepas'); @endphp
            <tr>
                <td colspan="3">Total aset dimiliki</td>
                <td class="r">{{ $rp($aktif->sum(fn ($b) => $b['aset']->harga_perolehan)) }}</td>
                <td></td>
                <td class="r">{{ $rp($aktif->sum('per_bulan')) }}</td>
                <td class="r">{{ $rp($aktif->sum('akumulasi')) }}</td>
                <td class="r">{{ $rp($aktif->sum('nilai_buku')) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
