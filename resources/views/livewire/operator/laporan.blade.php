<div data-rahasia>
    {{-- Judul + periode --}}
    <div class="mb-4">
        <div class="label">{{ __('Periode laporan') }}</div>
        <h1 class="text-xl font-semibold tracking-tight">
            @if ($dariTgl->isSameDay($sampaiTgl))
                {{ $dariTgl->translatedFormat('l, d F Y') }}
            @else
                {{ $dariTgl->translatedFormat('d M Y') }} – {{ $sampaiTgl->translatedFormat('d M Y') }}
            @endif
        </h1>
    </div>

    <div class="flex flex-wrap items-center gap-1.5 mb-5">
        @foreach (\App\Livewire\Concerns\PeriodeLaporan::daftarPeriode() as $kode => $nama)
            <button type="button" wire:click="pilihPeriode('{{ $kode }}')"
                    @class([
                        'btn h-8 px-3 text-xs font-mono uppercase tracking-wider',
                        'btn-primary' => $periode === $kode,
                        'text-muted' => $periode !== $kode,
                    ])>{{ __($nama) }}</button>
        @endforeach

        @if ($periode === 'custom')
            <input type="date" wire:model.live="dari" class="input num w-auto h-8">
            <span class="text-muted">–</span>
            <input type="date" wire:model.live="sampai" class="input num w-auto h-8">
        @endif

        <span wire:loading class="label ml-2">{{ __('Memuat...') }}</span>
    </div>

    @include('laporan.isi')
</div>
