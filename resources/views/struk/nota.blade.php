@php
    $rp = fn ($n) => ((int) $n < 0 ? '-Rp ' : 'Rp ').number_format(abs((int) $n), 0, ',', '.');
    $metode = \App\Services\Struk\StrukService::METODE;
    $logoUrl = \App\Support\Tema::logoUrl();
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nota {{ $trx->nomor }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef2f6; color: #0f1c2b; font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .alat { display: flex; gap: 8px; justify-content: center; padding: 12px 16px; }
        .alat button, .alat a { font: inherit; padding: 8px 16px; border-radius: 6px; border: 1px solid #c5d0dc; background: #fff; color: inherit; text-decoration: none; cursor: pointer; }
        .alat button { background: #0f1c2b; border-color: #0f1c2b; color: #fff; }
        .kertas { background: #fff; max-width: 210mm; min-height: 297mm; margin: 0 auto 24px; padding: 18mm 16mm; }
        .kepala { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #0f1c2b; padding-bottom: 12px; }
        .kepala img { max-height: 56px; max-width: 160px; object-fit: contain; }
        h1 { font-size: 20px; margin: 4px 0 2px; }
        .muted { color: #5b6b80; }
        .kanan { text-align: right; }
        .judul { font-size: 22px; font-weight: 700; letter-spacing: .04em; }
        .info { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; margin: 16px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { text-align: left; font-size: 12px; text-transform: uppercase; color: #5b6b80; border-bottom: 1px solid #c5d0dc; padding: 8px 6px; }
        td { padding: 8px 6px; border-bottom: 1px solid #eef2f6; vertical-align: top; }
        .ringkas { margin-left: auto; width: 280px; margin-top: 12px; }
        .ringkas td { border: none; padding: 3px 6px; }
        .ringkas .total td { border-top: 2px solid #0f1c2b; font-weight: 700; font-size: 16px; padding-top: 8px; }
        .cap { display: inline-block; margin-top: 16px; padding: 6px 14px; border: 2px solid; border-radius: 6px; font-weight: 700; letter-spacing: .08em; }
        .cap.batal { color: #dc2626; }
        .cap.belum { color: #d97706; }
        .cap.lunas { color: #16a34a; }
        .kaki { margin-top: 32px; text-align: center; }
        @media (max-width: 640px) {
            .kertas { padding: 16px; min-height: 0; }
            .info { grid-template-columns: 1fr; }
            .ringkas { width: 100%; }
        }
        @media print {
            @page { size: A4; margin: 12mm; }
            body { background: #fff; }
            .alat { display: none; }
            .kertas { margin: 0; padding: 0; min-height: 0; max-width: none; }
        }
    </style>
</head>
<body>
    <div class="alat">
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
        <a href="javascript:history.length > 1 ? history.back() : window.close()">Kembali</a>
    </div>

    <div class="kertas">
        <div class="kepala">
            <div>
                @if ($logoUrl)
                    <img src="{{ $logoUrl }}" alt="Logo">
                @endif
                <h1>{{ $cabang?->tenant?->nama ?? config('app.name') }}</h1>
                <div class="muted">
                    {{ $cabang?->nama }}
                    @if ($cabang?->alamat)<br>{{ $cabang->alamat }}@endif
                    @if ($cabang?->telepon)<br>Telp {{ $cabang->telepon }}@endif
                    @if ($setelan['header'])<br>{!! nl2br(e($setelan['header'])) !!}@endif
                </div>
            </div>
            <div class="kanan">
                <div class="judul">NOTA</div>
                <div>{{ $trx->nomor }}</div>
                <div class="muted">{{ ($trx->dibayar_pada ?? $trx->created_at)->format('d/m/Y H:i') }}</div>
            </div>
        </div>

        <div class="info">
            @if ($member)
                <div><span class="muted">Member:</span> {{ $member['nama'] }} ({{ $member['kode'] }} · {{ $member['tier'] }}) · Saldo {{ $rp($member['saldo']) }} · {{ $member['poin'] }} poin</div>
            @else
                <div><span class="muted">Pelanggan:</span> {{ $trx->pelanggan_nama ?: 'Tamu' }}</div>
            @endif
            <div><span class="muted">Kasir:</span> {{ $trx->user?->name }}</div>
            @if ($trx->unit)
                <div><span class="muted">Unit:</span> {{ $trx->unit->nama }}</div>
            @endif
        </div>

        <table>
            <thead>
                <tr><th>Keterangan</th><th class="kanan">Qty</th><th class="kanan">Harga</th><th class="kanan">Subtotal</th></tr>
            </thead>
            <tbody>
                @forelse ($trx->items as $item)
                    <tr>
                        <td>
                            {{ $item->nama }}
                            @if ($item->jenis === \App\Models\TransaksiItem::JENIS_SEWA && $item->catatan)
                                <div class="muted" style="font-size: 12px;">{{ $item->catatan }}</div>
                            @endif
                        </td>
                        <td class="kanan">{{ $item->qty }}</td>
                        <td class="kanan">{{ $rp($item->harga_satuan) }}</td>
                        <td class="kanan">{{ $rp($item->subtotal) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">Belum ada item.</td></tr>
                @endforelse
            </tbody>
        </table>

        <table class="ringkas">
            @if ($trx->total_diskon > 0)
                <tr><td>Subtotal</td><td class="kanan">{{ $rp($trx->subtotal) }}</td></tr>
                @foreach ($diskon as $d)
                    <tr><td>{{ $d->nama }}</td><td class="kanan">{{ $rp(-$d->nilai) }}</td></tr>
                @endforeach
            @endif
            <tr class="total"><td>Total</td><td class="kanan">{{ $rp($trx->total) }}</td></tr>
            @foreach ($trx->pembayaran as $p)
                <tr>
                    <td>{{ $metode[$p->metode] ?? $p->metode }}{{ $p->referensi ? ' · '.$p->referensi : '' }}</td>
                    <td class="kanan">{{ $rp($p->jumlah) }}</td>
                </tr>
            @endforeach
            @if ($trx->kembalian > 0)
                <tr><td>Kembalian</td><td class="kanan">{{ $rp($trx->kembalian) }}</td></tr>
            @endif
            @if ($sisa > 0)
                <tr><td><strong>Sisa tagihan</strong></td><td class="kanan"><strong>{{ $rp($sisa) }}</strong></td></tr>
            @endif
        </table>

        @if ($trx->isDibatalkan())
            <div class="cap batal">DIBATALKAN</div>
        @elseif (! $trx->isLunas() || $sisa > 0)
            <div class="cap belum">BELUM LUNAS</div>
        @else
            <div class="cap lunas">LUNAS</div>
        @endif

        <div class="kaki muted">
            {!! nl2br(e($setelan['footer'])) !!}
            <div style="font-size: 12px; margin-top: 4px;">Dicetak {{ now()->format('d/m/Y H:i') }}</div>
            <div style="font-size: 11px; margin-top: 4px;">{!! \App\Support\HakCipta::html() !!} · {{ preg_replace('#^https?://#', '', \App\Support\HakCipta::URL) }}</div>
        </div>
    </div>
</body>
</html>
