<x-filament-panels::page>
    <form wire:submit="simpan" class="space-y-6">
        {{ $this->form }}

        <div style="display:flex; gap:8px;">
            <x-filament::button type="submit">Simpan</x-filament::button>
            <x-filament::button type="button" color="gray" wire:click="tes" wire:loading.attr="disabled">Tes koneksi</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
