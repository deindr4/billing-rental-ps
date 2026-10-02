<div>
    <x-sheet wire:model="buka" :judul="'HDMI · '.($this->unit?->nama ?? 'TV')" max-width="sm:max-w-md">
        @if ($tv = $this->perangkat)
            <p class="text-sm text-muted mb-3">
                Pilih HDMI tempat konsol yang dipakai. TV yang sedang main langsung pindah; sesi berikutnya juga memakai HDMI ini. Tarif tidak berubah.
            </p>

            <div class="grid gap-2">
                @foreach ($tv->pilihanHdmi() as $id => $label)
                    @php $aktif = $tv->input_hdmi === $id; @endphp
                    <button type="button" wire:click="pilih(@js($id))" wire:loading.attr="disabled"
                            @class(['btn h-12 justify-between px-4', 'btn-primary' => $aktif, 'btn-tint tint-biru' => ! $aktif])>
                        <span class="flex items-center gap-2"><x-ikon name="rental" size="18" /> {{ $label }}</span>
                        @if ($aktif)
                            <span class="text-xs font-mono uppercase tracking-wider">Dipakai</span>
                        @endif
                    </button>
                @endforeach
            </div>

            <p class="text-xs text-muted mt-3">Nama konsol per HDMI (PS3 / PS4 / PS5) diatur di Admin → Perangkat TV → tombol HDMI.</p>
        @endif
    </x-sheet>
</div>
