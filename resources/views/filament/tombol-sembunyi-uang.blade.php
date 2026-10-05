{{-- Tombol mata di topbar admin: sembunyikan nominal di dasbor & laporan (lihat partials/sembunyi-uang) --}}
<div x-data="{ tutup: document.documentElement.classList.contains('sembunyi-uang') }"
     @sembunyi-uang.window="tutup = $event.detail">
    <span x-show="! tutup">
        <x-filament::icon-button icon="heroicon-o-eye" color="gray" label="Sembunyikan nominal (untuk foto layar)"
                                 tooltip="Sembunyikan nominal" x-on:click="ubahSembunyiUang()" />
    </span>
    <span x-show="tutup" x-cloak>
        <x-filament::icon-button icon="heroicon-o-eye-slash" color="gray" label="Tampilkan nominal"
                                 tooltip="Tampilkan nominal" x-on:click="ubahSembunyiUang()" />
    </span>
</div>
