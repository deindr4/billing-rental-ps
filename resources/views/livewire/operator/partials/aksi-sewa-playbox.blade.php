{{-- Tombol aksi satu sewa Playbox (dipakai tampilan kotak & daftar). Variabel: $s, $belumBayar, $ringkas (bool) --}}
@php $ringkas ??= false; @endphp
@if ($belumBayar)
    <button type="button" wire:click="bayar('{{ $s->transaksi_id }}')" class="btn btn-primary h-9 px-3 text-sm">{{ __('Bayar') }}</button>
@endif
@if ($s->status === 'berjalan')
    <a href="{{ route('playbox.kembali', ['id' => $s->id]) }}" wire:navigate class="btn btn-tint tint-hijau h-9 px-3 text-sm">{{ __('Kembali') }}</a>
    <button type="button" wire:click="bukaPerpanjang('{{ $s->id }}')" class="btn btn-tint tint-kuning h-9 px-3 text-sm">{{ __('Perpanjang') }}</button>
@endif
<a href="{{ route('playbox.surat', ['id' => $s->id]) }}" target="_blank" class="btn btn-ikon h-9 w-9" title="{{ __('Surat sewa') }}"><x-ikon name="transaksi" size="16" /></a>
@if ($s->penyewa?->telepon)
    <a href="{{ \App\Livewire\Operator\PlayboxSewa::linkWa($s) }}" target="_blank" rel="noopener" class="btn btn-ikon h-9 w-9 text-ik-hijau" title="{{ __('WhatsApp penyewa') }}"><x-ikon name="pengumuman" size="16" /></a>
@endif
@if ($s->urlMaps())
    <a href="{{ $s->urlMaps() }}" target="_blank" rel="noopener" class="btn btn-ikon h-9 w-9 text-ik-biru" title="{{ __('Lokasi di Google Maps') }}"><x-ikon name="cabang" size="16" /></a>
@endif
@if ($s->status === 'berjalan')
    <x-confirm-button action="batal" :params="[$s->id]" :title="__('Batalkan sewa :nomor?', ['nomor' => $s->nomor])"
                      :text="__('Tagihan dibatalkan (uang dikembalikan bila sudah dibayar), deposit dikembalikan, unit tersedia lagi.')"
                      :confirm-text="__('Ya, batalkan')" danger reason class="btn-tint tint-merah h-9 px-3 text-sm {{ $ringkas ? '' : 'ml-auto' }}">{{ __('Batal') }}</x-confirm-button>
@endif
