{{-- Data pelanggan: tamu / member terdaftar (Stitch 08). Dipakai komponen dengan trait PilihMember. --}}
@php
    $m = $this->member;
    $info = $m ? $this->infoMember() : [];
@endphp
<div class="space-y-3">
    <div class="grid grid-cols-2 gap-1.5 rounded-md border border-line bg-bg p-1">
        <button type="button" wire:click="$set('jenisPelanggan', 'tamu')"
                @class(['h-9 rounded text-sm font-medium', 'bg-surface-2 text-fg border border-line' => $jenisPelanggan === 'tamu', 'text-muted' => $jenisPelanggan !== 'tamu'])>
            {{ __('Non-Member / Tamu') }}
        </button>
        <button type="button" wire:click="$set('jenisPelanggan', 'member')"
                @class(['h-9 rounded text-sm font-medium', 'bg-surface-2 text-fg border border-line' => $jenisPelanggan === 'member', 'text-muted' => $jenisPelanggan !== 'member'])>
            {{ __('Member Terdaftar') }}
        </button>
    </div>

    @if ($jenisPelanggan === 'member')
        @if ($m)
            <x-kartu-member :member="$m" :diskon="$info['diskon']" :berikutnya="$info['berikutnya']">
                <button type="button" wire:click="lepasMember" class="text-xs text-accent shrink-0">{{ __('Ganti') }}</button>
            </x-kartu-member>
        @elseif ($daftarBaru)
            <div class="rounded-md border border-line p-3 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="label">{{ __('Daftar member baru') }}</span>
                    <button type="button" wire:click="$set('daftarBaru', false)" class="text-xs text-muted">{{ __('Batal') }}</button>
                </div>
                <div>
                    <input type="text" wire:model="daftarNama" class="input" placeholder="{{ __('Nama lengkap') }}" maxlength="100">
                    @error('daftarNama') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <input type="tel" inputmode="tel" wire:model="daftarTelepon" class="input num" placeholder="{{ __('Nomor HP / WhatsApp') }}" maxlength="20">
                    @error('daftarTelepon') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <button type="button" wire:click="simpanDaftarBaru" class="btn btn-primary w-full h-10"
                        wire:loading.attr="disabled" wire:target="simpanDaftarBaru">{{ __('Daftarkan & pilih') }}</button>
            </div>
        @else
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-muted" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="search" wire:model.live.debounce.300ms="cariMember" class="input pl-9 num"
                       placeholder="{{ __('Cari nomor HP, nama, atau kode member') }}" autocomplete="off">
            </div>

            @if ($this->hasilMember->isNotEmpty())
                <ul class="rounded-md border border-line divide-y divide-line">
                    @foreach ($this->hasilMember as $h)
                        <li wire:key="hasil-member-{{ $h->id }}">
                            <button type="button" wire:click="pilihMember('{{ $h->id }}')"
                                    class="w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-surface-2">
                                <span class="h-8 w-8 shrink-0 rounded-md grid place-items-center bg-surface-2 text-xs font-semibold">{{ $h->inisial() }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium truncate">{{ $h->nama }}</span>
                                    <span class="block text-xs text-muted num">{{ $h->kode }} · {{ $h->telepon }}</span>
                                </span>
                                <span class="chip">{{ $h->tier }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @elseif (mb_strlen(trim($cariMember)) >= 2)
                <p class="text-sm text-muted">{{ __('Member tidak ditemukan.') }}</p>
            @endif

            @can('member.kelola')
                <button type="button" wire:click="bukaDaftarBaru" class="btn btn-ghost w-full text-sm text-muted">
                    + {{ __('Daftar member baru') }}
                </button>
            @endcan
        @endif
    @endif
</div>
