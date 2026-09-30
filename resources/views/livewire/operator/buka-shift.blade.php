<div class="max-w-md mx-auto pt-6">
    <div class="mb-5">
        <h1 class="text-xl font-semibold">Buka Shift</h1>
        <p class="text-sm text-muted">Hitung uang di laci kas, lalu masukkan sebagai kas awal.</p>
    </div>

    <form wire:submit="simpan" class="surface p-5 space-y-4">
        <div>
            <label for="kasAwal" class="block text-sm mb-1.5">Kas awal</label>
            <x-input-uang id="kasAwal" wire:model="kasAwal" class="text-lg" autofocus />
            @error('kasAwal')
                <p class="text-sm text-danger mt-1.5">{{ $message }}</p>
            @enderror
        </div>

        @if ($kasAkhirSebelumnya !== null)
            <button type="button" wire:click="pakaiKasSebelumnya"
                    class="w-full text-left text-sm text-muted hover:text-fg">
                Kas akhir shift sebelumnya:
                <span class="num text-fg">Rp {{ number_format($kasAkhirSebelumnya, 0, ',', '.') }}</span>
                · <span class="text-accent">Pakai</span>
            </button>
        @endif

        <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="simpan">Buka Shift</span>
            <span wire:loading wire:target="simpan">Memproses...</span>
        </button>
    </form>
</div>
