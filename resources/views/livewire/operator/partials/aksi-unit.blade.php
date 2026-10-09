{{-- Tombol aksi utama unit (kartu & daftar). Variabel: $unit, $sesi, $ringkas (bool, tampilan daftar) --}}
@php $ringkas ??= false; @endphp
@switch($unit->status)
    @case('kosong')
        <button type="button"
                wire:click="$dispatch('buka-mulai-sesi', { unitId: '{{ $unit->id }}' })"
                @class(['btn btn-primary', 'w-full' => ! $ringkas, 'h-9 px-3 text-sm' => $ringkas])>
            <x-ikon name="play" size="16" /> Mulai{{ $ringkas ? '' : ' Rental' }}
        </button>
        @break

    @case('main')
    @case('pause')
        @if ($sesi?->sedangPilihGame())
            <button type="button" wire:click="mulaiSekarang('{{ $sesi->id }}')" title="Pelanggan siap · mulai waktu sekarang"
                    @class(['btn btn-tint tint-hijau text-sm', 'w-full mb-2' => ! $ringkas, 'h-9 px-3' => $ringkas])>
                <x-ikon name="play" size="14" /> {{ $ringkas ? 'Mulai waktu' : 'Pelanggan siap · mulai waktu sekarang' }}
            </button>
        @endif
        <div @class(['flex gap-2', 'gap-1.5' => $ringkas])>
            @if ($sesi?->mode === 'paket')
                <button type="button" title="Tambah waktu"
                        wire:click="$dispatch('buka-kelola-sesi', { unitId: '{{ $unit->id }}', panel: 'tambah' })"
                        @class(['btn btn-ikon text-ik-kuning', 'h-9 w-9' => $ringkas])>
                    <x-ikon name="jam" size="18" />
                </button>
            @endif
            <a href="{{ route('pos', ['unit' => $unit->id]) }}" wire:navigate title="Tambah F&B"
               @class(['btn btn-ikon text-ik-oranye', 'h-9 w-9' => $ringkas])>
                <x-ikon name="fnb" size="18" />
            </a>
            <button type="button"
                    wire:click="$dispatch('buka-kelola-sesi', { unitId: '{{ $unit->id }}' })"
                    @class(['btn btn-tint tint-biru', 'flex-1' => ! $ringkas, 'h-9 px-3 text-sm' => $ringkas])>
                <x-ikon name="kelola" size="16" /> {{ $ringkas ? 'Kelola' : 'Kelola Sesi' }}
            </button>
        </div>
        @break

    @case('menunggu_bayar')
        @if ($sesi)
            <button type="button"
                    wire:click="$dispatch('buka-pembayaran', { transaksiId: '{{ $sesi->transaksi_id }}' })"
                    @class(['btn btn-primary', 'w-full' => ! $ringkas, 'h-9 px-3 text-sm' => $ringkas])>
                <x-ikon name="bayar" size="16" /> Bayar
            </button>
        @endif
        @break

    @case('servis')
        <x-confirm-button action="tandaiSiap"
                          :params="[$unit->id]"
                          title="Unit sudah selesai diperbaiki?"
                          text="Status akan kembali Ready dan unit bisa dipakai lagi."
                          confirm-text="Ya, siap dipakai"
                          :class="$ringkas ? 'h-9 px-3 text-sm' : 'w-full'">
            {{ $ringkas ? 'Tandai siap' : 'Tandai Siap Dipakai' }}
        </x-confirm-button>
        @break

    @default
        @unless ($ringkas)<div class="h-9"></div>@endunless
@endswitch
