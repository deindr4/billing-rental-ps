{{-- Form perpanjang inline (kotak & daftar). Variabel: $s --}}
<div class="space-y-2">
    <div class="flex flex-wrap gap-1.5">
        @foreach ($s->playbox?->tarif() ?? [] as $sat => $h)
            <button type="button" wire:click="$set('satuan', '{{ $sat }}')" @class(['btn h-8 px-2.5 text-xs', 'btn-primary' => $satuan === $sat])>
                {{ \App\Models\Playbox::SATUAN[$sat] }} · <x-rupiah :nilai="$h" />
            </button>
        @endforeach
    </div>
    <div class="flex items-center gap-2">
        <input type="number" min="1" wire:model.live="jumlah" class="input num w-20 h-9">
        <span class="text-sm text-muted flex-1">{{ \App\Models\Playbox::SATUAN[$satuan] ?? '' }} · <x-rupiah :nilai="($s->playbox?->harga($satuan) ?? 0) * max(1, $jumlah)" /></span>
        <button type="button" wire:click="$set('perpanjangId', null)" class="btn h-9 px-3 text-sm">Batal</button>
        <button type="button" wire:click="perpanjang" class="btn btn-primary h-9 px-3 text-sm">Perpanjang</button>
    </div>
</div>
