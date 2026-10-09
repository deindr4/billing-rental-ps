{{-- Tombol aksi utama unit (kartu & daftar). Variabel: $unit, $sesi, $ringkas (bool, tampilan daftar) --}}
@php $ringkas ??= false; @endphp
@switch($unit->status)
    @case('kosong')
        <button type="button"
                wire:click="$dispatch('buka-mulai-sesi', { unitId: '{{ $unit->id }}' })"
                @class(['btn btn-primary', 'w-full' => ! $ringkas, 'h-9 px-3 text-sm' => $ringkas])>
            <x-ikon name="play" size="16" /> {{ $ringkas ? __('Mulai') : __('Mulai Rental') }}
        </button>
        @break

    @case('main')
    @case('pause')
        @if ($sesi?->sedangPilihGame())
            <button type="button" wire:click="mulaiSekarang('{{ $sesi->id }}')" title="{{ __('Pelanggan siap · mulai waktu sekarang') }}"
                    @class(['btn btn-tint tint-hijau text-sm', 'w-full mb-2' => ! $ringkas, 'h-9 px-3' => $ringkas])>
                <x-ikon name="play" size="14" /> {{ $ringkas ? __('Mulai waktu') : __('Pelanggan siap · mulai waktu sekarang') }}
            </button>
        @endif
        <div @class(['flex gap-2', 'gap-1.5' => $ringkas])>
            @if ($sesi?->mode === 'paket')
                <button type="button" title="{{ __('Tambah waktu') }}"
                        wire:click="$dispatch('buka-kelola-sesi', { unitId: '{{ $unit->id }}', panel: 'tambah' })"
                        @class(['btn btn-ikon text-ik-kuning', 'h-9 w-9' => $ringkas])>
                    <x-ikon name="jam" size="18" />
                </button>
            @endif
            <a href="{{ route('pos', ['unit' => $unit->id]) }}" wire:navigate title="{{ __('Tambah F&B') }}"
               @class(['btn btn-ikon text-ik-oranye', 'h-9 w-9' => $ringkas])>
                <x-ikon name="fnb" size="18" />
            </a>
            <button type="button"
                    wire:click="$dispatch('buka-kelola-sesi', { unitId: '{{ $unit->id }}' })"
                    @class(['btn btn-tint tint-biru', 'flex-1' => ! $ringkas, 'h-9 px-3 text-sm' => $ringkas])>
                <x-ikon name="kelola" size="16" /> {{ $ringkas ? __('Kelola') : __('Kelola Sesi') }}
            </button>
        </div>
        @break

    @case('menunggu_bayar')
        @if ($sesi)
            <button type="button"
                    wire:click="$dispatch('buka-pembayaran', { transaksiId: '{{ $sesi->transaksi_id }}' })"
                    @class(['btn btn-primary', 'w-full' => ! $ringkas, 'h-9 px-3 text-sm' => $ringkas])>
                <x-ikon name="bayar" size="16" /> {{ __('Bayar') }}
            </button>
        @endif
        @break

    @case('servis')
        <x-confirm-button action="tandaiSiap"
                          :params="[$unit->id]"
                          :title="__('Unit sudah selesai diperbaiki?')"
                          :text="__('Status akan kembali Ready dan unit bisa dipakai lagi.')"
                          :confirm-text="__('Ya, siap dipakai')"
                          :class="$ringkas ? 'h-9 px-3 text-sm' : 'w-full'">
            {{ $ringkas ? __('Tandai siap') : __('Tandai Siap Dipakai') }}
        </x-confirm-button>
        @break

    @default
        @unless ($ringkas)<div class="h-9"></div>@endunless
@endswitch
