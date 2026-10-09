@php
    $warna = ['penting' => 'text-ik-merah bg-ik-merah/15', 'peringatan' => 'text-ik-oranye bg-ik-oranye/15', 'info' => 'text-ik-biru bg-ik-biru/15'];
@endphp
<div class="relative" x-data="{ buka: false }" @keydown.escape.window="buka = false" wire:poll.30s="periksa">
    <button type="button" class="btn btn-ghost btn-ikon text-muted relative" @click="buka = ! buka"
            title="{{ __('Notifikasi') }}" aria-label="{{ __('Notifikasi') }}" :aria-expanded="buka">
        <x-ikon name="lonceng" size="18" />
        @if ($this->jumlah > 0)
            <span class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full bg-ik-merah text-white text-[10px] font-bold leading-[18px] text-center">
                {{ $this->jumlah > 99 ? '99+' : $this->jumlah }}
            </span>
        @endif
    </button>

    <div x-show="buka" x-cloak @click.outside="buka = false" x-transition.opacity.duration.100ms
         class="fixed sm:absolute inset-x-2 sm:inset-x-auto top-16 sm:top-full sm:right-0 sm:mt-2 z-50 sm:w-[24rem] kartu shadow-xl flex flex-col max-h-[75vh]">
        <div class="flex items-center justify-between gap-2 px-4 py-3 border-b border-line">
            <div class="font-semibold">{{ __('Notifikasi') }}
                @if ($this->jumlah > 0)
                    <span class="text-xs text-muted font-normal">· {{ __(':n belum dibaca', ['n' => $this->jumlah > 99 ? '99+' : $this->jumlah]) }}</span>
                @endif
            </div>
            @if ($this->jumlah > 0)
                <button type="button" wire:click="tandaiSemua" class="text-xs text-ik-biru hover:underline">{{ __('Tandai semua dibaca') }}</button>
            @endif
        </div>

        <div class="overflow-y-auto divide-y divide-line">
            @forelse ($this->daftar as $n)
                <button type="button" wire:key="lonceng-{{ $n->id }}" wire:click="buka('{{ $n->id }}')"
                        @class(['w-full text-left px-4 py-3 flex gap-3 hover:bg-surface-2 transition', 'bg-surface-2/60' => ! $n->dibaca])>
                    <span class="h-8 w-8 shrink-0 rounded-full grid place-items-center {{ $warna[$n->tingkat] ?? $warna['info'] }}">
                        <x-ikon :name="\App\Services\Notifikasi\Lonceng::ikon($n->kelompok)" size="16" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span @class(['block text-sm leading-snug', 'font-semibold' => ! $n->dibaca, 'text-muted' => $n->dibaca])>{{ $n->judul }}</span>
                        @if ($n->isi)
                            <span class="block text-xs text-muted line-clamp-2 mt-0.5">{{ $n->isi }}</span>
                        @endif
                        <span class="block label mt-1">
                            {{ $n->created_at->diffForHumans() }}
                            @if ($n->tingkat === 'penting') · <span class="text-ik-merah">{{ __('Penting') }}</span>@endif
                        </span>
                    </span>
                    @unless ($n->dibaca)
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-ik-biru" aria-label="{{ __('Belum dibaca') }}"></span>
                    @endunless
                </button>
            @empty
                <div class="px-4 py-10 text-center text-sm text-muted">
                    <x-ikon name="lonceng" size="28" class="mx-auto mb-2 opacity-40" />
                    {{ __('Belum ada notifikasi.') }}
                </div>
            @endforelse
        </div>

        <a href="{{ route('notifikasi') }}" wire:navigate @click="buka = false"
           class="block px-4 py-3 border-t border-line text-center text-sm text-ik-biru hover:bg-surface-2">
            {{ __('Lihat semua notifikasi') }}
        </a>
    </div>
</div>
