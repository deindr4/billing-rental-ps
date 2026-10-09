<div class="min-h-screen grid place-items-center p-4">
    <div class="w-full max-w-md">
        <div class="mb-6">
            <h1 class="text-xl font-semibold">{{ __('Pilih Cabang') }}</h1>
            <p class="text-sm text-muted">{{ __('Halo :nama, pilih cabang yang akan dibuka.', ['nama' => auth()->user()->name]) }}</p>
        </div>

        @error('cabang')
            <p class="text-sm text-danger mb-3">{{ $message }}</p>
        @enderror

        <div class="space-y-2">
            @foreach ($this->daftarCabang as $cabang)
                <button type="button"
                        wire:click="pilih('{{ $cabang->id }}')"
                        wire:loading.attr="disabled"
                        class="surface w-full p-4 text-left flex items-center justify-between gap-3 hover:border-accent">
                    <span>
                        <span class="block font-medium">{{ $cabang->nama }}</span>
                        <span class="block text-sm text-muted">{{ $cabang->kode }}{{ $cabang->alamat ? ' · '.$cabang->alamat : '' }}</span>
                    </span>
                    <span class="text-muted">›</span>
                </button>
            @endforeach
        </div>

        <form method="POST" action="{{ route('logout') }}" class="mt-6">
            @csrf
            <button type="submit" class="btn btn-ghost w-full text-muted">{{ __('Keluar') }}</button>
        </form>
    </div>
</div>
