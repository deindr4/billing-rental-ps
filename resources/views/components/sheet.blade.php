{{--
    Panel dialog: dari bawah di HP, di tengah di desktop.
    <x-sheet wire:model="buka" judul="Judul"> isi <x-slot:footer> tombol </x-slot:footer> </x-sheet>
--}}
@props(['judul' => '', 'maxWidth' => 'sm:max-w-lg'])

@php
    $model = $attributes->wire('model')->value();
@endphp

<div x-data="{ buka: $wire.entangle('{{ $model }}') }"
     x-show="buka"
     x-cloak
     x-on:keydown.escape.window="buka = false"
     class="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:p-4">

    {{-- Latar gelap --}}
    <div class="absolute inset-0 bg-black/60"
         x-show="buka"
         x-transition.opacity.duration.150ms
         @click="buka = false"></div>

    {{-- Panel --}}
    <div x-show="buka"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="translate-y-full sm:translate-y-0 sm:opacity-0 sm:scale-95"
         x-transition:enter-end="translate-y-0 sm:opacity-100 sm:scale-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="translate-y-0 opacity-100"
         x-transition:leave-end="translate-y-full sm:translate-y-0 opacity-0"
         class="relative w-full {{ $maxWidth }} bg-surface border border-line rounded-t-lg sm:rounded-lg max-h-[90vh] flex flex-col"
         style="padding-bottom: env(safe-area-inset-bottom, 0px);">

        <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-line">
            <h2 class="font-semibold truncate">{{ $judul }}</h2>
            <button type="button" class="btn btn-ghost h-8 px-2 text-muted" @click="buka = false" aria-label="Tutup">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="p-4 overflow-y-auto">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="px-4 py-3 border-t border-line">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
