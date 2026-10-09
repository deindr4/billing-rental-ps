@php $url = $this->urlBillboard(); @endphp
<div>
    <div class="mb-4">
        <div class="label">{{ __('Halaman publik untuk pelanggan · dibuka di HP lewat link') }}</div>
        <h1 class="text-xl font-semibold tracking-tight">{{ __('Billboard') }}</h1>
    </div>

    <div class="grid gap-4 lg:grid-cols-[1fr_380px]">
        <section class="space-y-4">
            <div class="kartu p-4 space-y-3">
                <div class="label">{{ __('Link billboard') }}</div>
                <p class="text-sm text-muted">
                    {{ __('Pelanggan bisa melihat unit yang kosong, sisa waktu yang sedang main, tarif, booking hari ini, turnamen, dan menu F&B — diperbarui otomatis. Bagikan di bio Instagram, status WhatsApp, atau grup pelanggan.') }}
                </p>
                <div class="rounded-md border border-line bg-bg px-3 py-2 text-sm break-all num">{{ $url }}</div>
                <div class="flex flex-wrap gap-2" x-data="{ tersalin: false }">
                    <button type="button" class="btn btn-primary h-10 px-4"
                            @click="navigator.clipboard.writeText(@js($url)).then(() => { tersalin = true; setTimeout(() => tersalin = false, 2000) })">
                        <span x-text="tersalin ? @js(__('Tersalin ✓')) : @js(__('Salin link'))"></span>
                    </button>
                    <a href="https://wa.me/?text={{ urlencode(__('Cek unit kosong & sisa waktu main sekarang: :url', ['url' => $url])) }}" target="_blank" class="btn btn-tint tint-hijau h-10 px-4">{{ __('Bagikan ke WhatsApp') }}</a>
                    <a href="{{ $url }}" target="_blank" class="btn btn-tint tint-teal h-10 px-4">{{ __('Buka') }} ↗</a>
                </div>
            </div>

            <div class="kartu p-4 space-y-3">
                <div class="label">{{ __('Teks berjalan / pengumuman') }}</div>
                <textarea wire:model="pengumuman" rows="3" class="input" maxlength="500" placeholder="{{ __('Promo begadang 6 jam Rp50.000 mulai 23:00 · Turnamen FC 25 Sabtu ini!') }}"></textarea>
                @error('pengumuman') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                <button type="button" wire:click="simpan" class="btn btn-primary h-10 px-4">{{ __('Simpan') }}</button>
            </div>

            <button type="button" wire:click="gantiKunci" wire:confirm="{{ __('Ganti link billboard? Link lama yang sudah dibagikan tidak bisa dibuka lagi.') }}"
                    class="btn btn-ghost h-8 px-2 text-xs text-muted">{{ __('Ganti link (jika link tersebar ke tempat yang tidak diinginkan)') }}</button>
        </section>

        {{-- Pratinjau ukuran HP --}}
        <section class="kartu p-4">
            <div class="label mb-2">{{ __('Pratinjau di HP') }}</div>
            <div class="mx-auto rounded-[28px] border-4 border-line overflow-hidden bg-bg" style="width: 320px; height: 620px;" wire:ignore>
                <iframe src="{{ $url }}" title="{{ __('Pratinjau billboard') }}" loading="lazy" style="width: 100%; height: 100%; border: 0;"></iframe>
            </div>
        </section>
    </div>
</div>
