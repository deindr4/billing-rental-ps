<div wire:poll.60s>
    @if ($jumlah !== null)
        <x-filament::icon-button tag="a" :href="route('notifikasi')" icon="heroicon-o-bell" color="gray"
                                 label="Notifikasi" tooltip="Notifikasi"
                                 :badge="$jumlah > 0 ? ($jumlah > 99 ? '99+' : $jumlah) : null" badge-color="danger" />
    @endif
</div>
