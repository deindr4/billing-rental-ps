<div wire:poll.15s>
    {{-- Judul + cari --}}
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
            <div class="label">{{ $jenis === 'pc' ? __('Rental PC') : __('Rental PS') }}</div>
            <h1 class="text-xl font-semibold tracking-tight">{{ __('Matriks Unit') }}</h1>
        </div>
        <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
            {{-- Pesan ke semua TV & running text promo --}}
            <button type="button" wire:click="$dispatch('buka-pemberitahuan')" title="{{ __('Pemberitahuan ke semua TV') }}"
                    class="btn btn-tint tint-pink h-9 px-3 text-sm">
                <x-ikon name="pengumuman" size="16" /> <span class="hidden min-[420px]:inline">{{ __('Pemberitahuan') }}</span>
            </button>
            <button type="button" wire:click="$dispatch('buka-running-text')" title="{{ __('Running text di TV') }}"
                    @class(['btn h-9 px-3 text-sm', 'btn-tint tint-hijau' => $runningText, 'btn-tint tint-teal' => ! $runningText])>
                @if ($runningText)
                    <span class="titik-w" style="--w: var(--ikon-hijau)"></span>
                @else
                    <x-ikon name="teks-jalan" size="16" />
                @endif
                <span class="hidden min-[420px]:inline">{{ __('Running text') }}{{ $runningText ? ' · ON' : '' }}</span>
            </button>
            <input type="search" wire:model.live.debounce.300ms="cari"
                   class="input flex-1 sm:w-64 sm:flex-none" placeholder="{{ __('Cari unit...') }}">
        </div>
    </div>

    {{-- Filter status --}}
    @php
        $tab = [
            'semua' => __('Semua Unit'),
            'kosong' => __('Ready'),
            'main' => __('Terisi'),
            'menunggu_bayar' => __('Menunggu Bayar'),
            'servis' => __('Maintenance'),
        ];
        // Warna titik = warna status unit di kartu
        $warnaTab = [
            'kosong' => 'var(--status-kosong)',
            'main' => 'var(--status-main)',
            'menunggu_bayar' => 'var(--status-hampir-habis)',
            'servis' => 'var(--status-servis)',
        ];
    @endphp
    <div class="flex items-center gap-1.5 mb-4">
    <div class="flex gap-1.5 overflow-x-auto pb-1 min-w-0">
        @foreach ($tab as $kunci => $teks)
            <button type="button" wire:click="$set('filterStatus', '{{ $kunci }}')"
                    @class([
                        'btn h-8 px-3 text-xs font-mono uppercase tracking-wider shrink-0',
                        'btn-primary' => $filterStatus === $kunci,
                        'text-muted' => $filterStatus !== $kunci,
                    ])>
                @if (isset($warnaTab[$kunci]) && $filterStatus !== $kunci)
                    <span class="titik-w" style="--w: {{ $warnaTab[$kunci] }}"></span>
                @endif
                {{ $teks }} <span class="opacity-70">({{ $ringkasan[$kunci] }})</span>
            </button>
        @endforeach
    </div>
        {{-- Tampilan kotak / daftar (diingat per login) --}}
        <div class="ml-auto shrink-0 flex rounded-md border border-line overflow-hidden mb-1">
            @foreach (['kotak' => __('Tampilan kotak'), 'daftar' => __('Tampilan daftar')] as $k => $l)
                <button type="button" wire:click="$set('tampilan', '{{ $k }}')" title="{{ $l }}" aria-label="{{ $l }}"
                        @class(['h-8 w-9 grid place-items-center', 'bg-accent text-[var(--accent-contrast)]' => $tampilan === $k, 'text-muted hover:text-fg' => $tampilan !== $k])>
                    <x-ikon :name="$k" size="16" />
                </button>
            @endforeach
        </div>
    </div>

    {{-- Unit: kotak (semua) atau daftar (per halaman) --}}
    @if ($units->isEmpty())
        <div class="kartu p-10 text-center text-muted">
            @if ($jenis === 'pc' && $ringkasan['semua'] === 0)
                {{ __('Belum ada unit PC. Buat tipe konsol berjenis PC di Admin → Tipe Konsol, lalu tambahkan unit dengan tipe itu.') }}
            @else
                {{ __('Tidak ada unit yang cocok.') }}
            @endif
        </div>
    @elseif ($tampilan === 'daftar')
        <div class="surface overflow-hidden">
            @foreach ($units as $unit)
                @php $sesi = $sesiPerUnit->get($unit->id); @endphp
                <x-baris-unit
                    wire:key="baris-{{ $unit->id }}-{{ $unit->status }}-{{ $sesi?->versi_tagihan ?? 0 }}-{{ $unit->warnaKartu() }}"
                    :unit="$unit"
                    :sesi="$sesi"
                    :aksesori="$sesi ? $aksesoriPerSesi->get($sesi->id) : null"
                    :tv="$tvPerUnit->get($unit->id)"
                    :bisa-remote="$bisaRemote"
                    :tarif="$tarif[$unit->id] ?? null"
                    :peringatan-menit="$peringatanMenit"
                    :server-now="$serverNow">
                    @include('livewire.operator.partials.aksi-unit', ['ringkas' => true])
                </x-baris-unit>
            @endforeach
        </div>
        @include('livewire.operator.partials.paginasi', ['p' => $units, 'satuan' => __('unit')])
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            @foreach ($units as $unit)
                @php $sesi = $sesiPerUnit->get($unit->id); @endphp

                <x-kartu-unit
                    wire:key="unit-{{ $unit->id }}-{{ $unit->status }}-{{ $sesi?->versi_tagihan ?? 0 }}-{{ $unit->warnaKartu() }}"
                    :unit="$unit"
                    :sesi="$sesi"
                    :aksesori="$sesi ? $aksesoriPerSesi->get($sesi->id) : null"
                    :tv="$tvPerUnit->get($unit->id)"
                    :bisa-remote="$bisaRemote"
                    :tarif="$tarif[$unit->id] ?? null"
                    :peringatan-menit="$peringatanMenit"
                    :server-now="$serverNow">
                    @include('livewire.operator.partials.aksi-unit')
                </x-kartu-unit>
            @endforeach
        </div>
    @endif

    {{-- Dialog --}}
    <livewire:operator.mulai-sesi />
    <livewire:operator.kelola-sesi />
    <livewire:operator.kelola-tv />
    <livewire:operator.kirim-pemberitahuan />
    <livewire:operator.pilih-hdmi-tv />
    <livewire:operator.kelola-running-text />
    <livewire:operator.pembayaran />
</div>
