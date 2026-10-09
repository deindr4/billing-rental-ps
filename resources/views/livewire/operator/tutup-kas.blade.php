<div class="max-w-lg mx-auto">
    @php
        $r = $this->ringkasan;
        $selisih = $this->selisih();
    @endphp

    <div class="mb-4">
        <h1 class="text-xl font-semibold">{{ __('Tutup Kas') }}</h1>
        <p class="text-sm text-muted">
            <span class="num">{{ $this->shift->nomor }}</span> · {{ __('dibuka :waktu', ['waktu' => $this->shift->dibuka_pada->format('d/m H:i')]) }}
            · {{ $this->shift->user?->name }}
        </p>
    </div>

    {{-- Ganti kasir di tengah hari: serah terima, bukan tutup kas --}}
    <a href="{{ route('shift.serah') }}" wire:navigate
       class="flex items-center justify-between gap-3 rounded-md border border-line px-3 py-2.5 mb-4 text-sm hover:border-accent">
        <span><span class="font-medium">{{ __('Ganti kasir?') }}</span> <span class="text-muted">{{ __('Pakai Serah Terima agar laci langsung dipegang kasir berikutnya.') }}</span></span>
        <x-ikon name="chevron" size="16" class="-rotate-90 text-muted shrink-0" />
    </a>

    {{-- Peringatan --}}
    @if ($r['sesi_aktif'] > 0 || $r['menunggu_bayar'] > 0)
        <div class="rounded-md border border-st-hampir px-3 py-2 mb-4 text-sm">
            @if ($r['sesi_aktif'] > 0)
                <div>{{ __(':n sesi masih berjalan. Sesi tetap berjalan, pembayarannya masuk ke shift berikutnya.', ['n' => $r['sesi_aktif']]) }}</div>
            @endif
            @if ($r['menunggu_bayar'] > 0)
                <div>{{ __(':n unit masih menunggu pembayaran.', ['n' => $r['menunggu_bayar']]) }}</div>
            @endif
        </div>
    @endif

    @include('livewire.operator.partials.ringkasan-kas', ['r' => $r])

    {{-- Input kas fisik --}}
    <div class="surface p-4 space-y-4">
        <div>
            <label for="kasFisik" class="block text-sm mb-1.5">{{ __('Uang di laci (hitung fisik)') }}</label>
            <x-input-uang id="kasFisik" wire:model.live="kasFisik" class="text-lg" />
            @error('kasFisik')
                <p class="text-sm text-danger mt-1.5">{{ $message }}</p>
            @enderror
        </div>

        @if ($selisih !== null)
            <div @class([
                'rounded-md border px-3 py-2 flex justify-between items-baseline',
                'border-line' => $selisih === 0,
                'border-danger text-danger' => $selisih !== 0,
            ])>
                <span class="text-sm">{{ $selisih === 0 ? __('Kas cocok') : ($selisih > 0 ? __('Kas lebih') : __('Kas kurang')) }}</span>
                <x-rupiah :nilai="abs($selisih)" class="text-xl font-semibold" />
            </div>
        @endif

        <div>
            <label for="ditinggal" class="block text-sm mb-1.5">{{ __('Ditinggal di laci untuk besok') }} <span class="text-muted">({{ __('modal kembalian') }})</span></label>
            <x-input-uang id="ditinggal" wire:model.live="ditinggal" />
            @if ($kasFisik !== null)
                <p class="text-sm mt-1.5 flex justify-between">
                    <span class="text-muted">{{ __('Disetor ke owner / brankas') }}</span>
                    <x-rupiah :nilai="(int) $kasFisik - $this->ditinggalEfektif()" class="font-semibold" />
                </p>
            @endif
        </div>

        @if ($selisih !== null && $selisih !== 0)
            <div>
                <label for="catatan" class="block text-sm mb-1.5">{{ __('Keterangan selisih') }}</label>
                <textarea id="catatan" wire:model="catatan" rows="2" class="input h-auto py-2"
                          placeholder="{{ __('Contoh: kembalian salah Rp 2.000') }}"></textarea>
                @error('catatan')
                    <p class="text-sm text-danger mt-1.5">{{ $message }}</p>
                @enderror
            </div>
        @endif

        <x-confirm-button action="tutup"
                          :title="__('Tutup shift sekarang?')"
                          :text="__('Setelah ditutup, transaksi baru harus memakai shift baru.')"
                          :confirm-text="__('Ya, tutup kas')"
                          danger
                          class="w-full h-11"
                          :disabled="$kasFisik === null">
            {{ __('Tutup Kas') }}
        </x-confirm-button>
    </div>
</div>
