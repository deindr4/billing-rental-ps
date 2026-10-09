@php
    $warna = ['penting' => 'text-ik-merah bg-ik-merah/15', 'peringatan' => 'text-ik-oranye bg-ik-oranye/15', 'info' => 'text-ik-biru bg-ik-biru/15'];
@endphp
<div class="max-w-4xl mx-auto">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
            <div class="label">{{ __('Lonceng') }}</div>
            <h1 class="text-xl font-semibold">{{ __('Notifikasi') }}</h1>
            <p class="text-sm text-muted">
                {{ __(':n belum dibaca', ['n' => $belumDibaca > 99 ? '99+' : $belumDibaca]) }} ·
                {{ __('disimpan :hari hari', ['hari' => \App\Services\Notifikasi\Lonceng::SIMPAN_HARI]) }}
            </p>
        </div>
        @if ($belumDibaca > 0)
            <button type="button" wire:click="tandaiSemua" class="btn h-10 px-4">{{ __('Tandai semua dibaca') }}</button>
        @endif
    </div>

    {{-- Filter --}}
    <div class="surface p-3 mb-4 grid gap-2 sm:grid-cols-3">
        <select wire:model.live="kelompok" class="input" aria-label="{{ __('Kelompok') }}">
            <option value="">{{ __('Semua kelompok') }}</option>
            @foreach ($kelompokBoleh as $kode => $k)
                <option value="{{ $kode }}">{{ __($k['label']) }}</option>
            @endforeach
        </select>
        <select wire:model.live="tingkat" class="input" aria-label="{{ __('Tingkat') }}">
            <option value="">{{ __('Semua tingkat') }}</option>
            @foreach (\App\Services\Notifikasi\Lonceng::TINGKAT as $kode => $label)
                <option value="{{ $kode }}">{{ __($label) }}</option>
            @endforeach
        </select>
        <label class="input flex items-center gap-2 cursor-pointer">
            <input type="checkbox" wire:model.live="belum" class="accent-current"> {{ __('Belum dibaca saja') }}
        </label>
    </div>

    <div class="kartu divide-y divide-line">
        @forelse ($halaman as $n)
            <button type="button" wire:key="n-{{ $n->id }}" wire:click="buka('{{ $n->id }}')"
                    @class(['w-full text-left px-4 py-3 flex gap-3 hover:bg-surface-2 transition', 'bg-surface-2/60' => ! $n->dibaca])>
                <span class="h-9 w-9 shrink-0 rounded-full grid place-items-center {{ $warna[$n->tingkat] ?? $warna['info'] }}">
                    <x-ikon :name="\App\Services\Notifikasi\Lonceng::ikon($n->kelompok)" size="18" />
                </span>
                <span class="min-w-0 flex-1">
                    <span @class(['block leading-snug', 'font-semibold' => ! $n->dibaca])>{{ $n->judul }}</span>
                    @if ($n->isi)
                        <span class="block text-sm text-muted mt-0.5">{{ $n->isi }}</span>
                    @endif
                    <span class="block label mt-1">
                        {{ __(\App\Services\Notifikasi\Lonceng::KELOMPOK[$n->kelompok]['label'] ?? $n->kelompok) }}
                        · {{ __(\App\Services\Notifikasi\Lonceng::TINGKAT[$n->tingkat] ?? $n->tingkat) }}
                        @if ($n->cabang) · {{ $n->cabang->nama }} @endif
                        · <span title="{{ $n->created_at->format('d/m/Y H:i') }}">{{ $n->created_at->format('d/m H:i') }} ({{ $n->created_at->diffForHumans() }})</span>
                    </span>
                </span>
                @unless ($n->dibaca)
                    <span class="mt-2 h-2 w-2 shrink-0 rounded-full bg-ik-biru" aria-label="{{ __('Belum dibaca') }}"></span>
                @endunless
            </button>
        @empty
            <div class="px-4 py-12 text-center text-sm text-muted">
                <x-ikon name="lonceng" size="32" class="mx-auto mb-2 opacity-40" />
                {{ __('Belum ada notifikasi.') }}
            </div>
        @endforelse
    </div>

    @include('livewire.operator.partials.paginasi', ['p' => $halaman, 'satuan' => __('notifikasi')])
</div>
