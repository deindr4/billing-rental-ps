<x-filament-panels::page>
    @vite(['resources/css/admin-laporan.css'])

    <div class="lap">
        {{-- Periode + cabang --}}
        <div class="flex flex-wrap items-center gap-1.5 mb-5">
            <select wire:model.live="cabang" class="input w-auto h-8 mr-2">
                @if ($bolehSemua)
                    <option value="">Semua cabang</option>
                @endif
                @foreach ($daftarCabang as $c)
                    <option value="{{ $c->id }}">{{ $c->nama }}</option>
                @endforeach
            </select>

            @foreach (\App\Livewire\Concerns\PeriodeLaporan::daftarPeriode() as $kode => $nama)
                <button type="button" wire:click="pilihPeriode('{{ $kode }}')"
                        @class([
                            'btn h-8 px-3 text-xs font-mono uppercase tracking-wider',
                            'btn-primary' => $periode === $kode,
                            'text-muted' => $periode !== $kode,
                        ])>{{ $nama }}</button>
            @endforeach

            @if ($periode === 'custom')
                <input type="date" wire:model.live="dari" class="input num w-auto h-8">
                <span class="text-muted">–</span>
                <input type="date" wire:model.live="sampai" class="input num w-auto h-8">
            @endif

            <span wire:loading class="label ml-2">Memuat...</span>
        </div>

        <div class="label mb-3">
            @if ($dariTgl->isSameDay($sampaiTgl))
                {{ $dariTgl->translatedFormat('l, d F Y') }}
            @else
                {{ $dariTgl->translatedFormat('d M Y') }} – {{ $sampaiTgl->translatedFormat('d M Y') }}
            @endif
            · {{ $cabang === '' ? 'Semua cabang' : ($daftarCabang[$cabang]->nama ?? '') }}
        </div>

        @include('laporan.isi')
    </div>
</x-filament-panels::page>
