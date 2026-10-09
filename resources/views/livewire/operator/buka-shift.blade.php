<div class="max-w-md mx-auto pt-6">
    @if ($dipegang)
        {{-- Satu laci per cabang: kasir lain masih memegang laci --}}
        <div class="mb-5">
            <h1 class="text-xl font-semibold">Laci sedang dipegang</h1>
            <p class="text-sm text-muted">Satu cabang hanya punya satu laci kasir.</p>
        </div>

        <div class="surface p-5 space-y-3">
            <div class="flex items-center gap-3">
                <span class="h-10 w-10 shrink-0 rounded-md grid place-items-center bg-surface-2 border border-line font-semibold">
                    {{ mb_substr($dipegang->user?->name ?? '?', 0, 1) }}
                </span>
                <div>
                    <div class="font-medium">{{ $dipegang->user?->name }}</div>
                    <div class="label">Shift <span class="num">{{ $dipegang->nomor }}</span> sejak {{ $dipegang->dibuka_pada->format('d/m H:i') }}</div>
                </div>
            </div>
            <p class="text-sm">
                Minta <b>{{ $dipegang->user?->name }}</b> membuka menu <b>Serah Terima</b> lalu memilih nama Anda.
                Anda mengetik PIN di perangkatnya untuk menerima laci.
            </p>
            <p class="text-xs text-muted">Kasir sebelumnya sudah pulang? Supervisor / owner bisa melakukan serah terima atas namanya.</p>
        </div>
    @else
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
                    Ditinggal di laci saat shift terakhir ditutup:
                    <span class="num text-fg">Rp {{ number_format($kasAkhirSebelumnya, 0, ',', '.') }}</span>
                    · <span class="text-accent">Pakai</span>
                </button>
            @endif

            <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="simpan">Buka Shift</span>
                <span wire:loading wire:target="simpan">Memproses...</span>
            </button>
        </form>
    @endif
</div>
