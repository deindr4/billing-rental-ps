@php
    /** @var \App\Models\Penyewa $p */
    $p = $getRecord();
    $h = $p->riwayat();
    $sewa = \App\Models\SewaPlaybox::withoutGlobalScopes()->with('playbox:id,kode')->where('penyewa_id', $p->id)->latest('mulai_pada')->limit(10)->get();
@endphp

<x-filament::section>
    <div class="flex flex-wrap gap-4">
        @foreach (['Foto' => $p->foto, 'KTP' => $p->foto_ktp] as $label => $path)
            <div>
                <div class="text-xs text-gray-500 mb-1">{{ $label }}</div>
                @if ($path)
                    <a href="{{ \App\Support\FotoPrivat::url($path) }}" target="_blank">
                        <img src="{{ \App\Support\FotoPrivat::url($path) }}" alt="{{ $label }}" class="h-32 rounded-lg object-cover border border-gray-200 dark:border-white/10">
                    </a>
                @else
                    <div class="h-32 w-24 rounded-lg grid place-items-center text-xs text-gray-400 border border-dashed border-gray-300 dark:border-white/10">Tidak ada</div>
                @endif
            </div>
        @endforeach
        <div class="text-sm space-y-1">
            <div><b>{{ $h['sewa'] }}</b> sewa · {{ $h['berjalan'] }} berjalan · {{ $h['telat'] }}× telat · kerusakan Rp{{ number_format($h['kerusakan'], 0, ',', '.') }}</div>
            @if ($p->urlMaps())
                <a href="{{ $p->urlMaps() }}" target="_blank" class="text-primary-600 underline">Buka lokasi di Google Maps</a>
            @endif
            @foreach ($sewa as $s)
                <div class="text-xs text-gray-500">{{ $s->mulai_pada->format('d/m/Y') }} · {{ $s->nomor }} · {{ $s->playbox?->kode }} · {{ $s->labelDurasi() }} · {{ $s->status }}{{ $s->denda ? ' · denda Rp'.number_format($s->denda, 0, ',', '.') : '' }}</div>
            @endforeach
        </div>
    </div>
</x-filament::section>
