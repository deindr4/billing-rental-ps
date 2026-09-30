@php $url = $this->urlBillboard(); @endphp
<div>
    <div class="mb-4">
        <div class="label">Halaman publik untuk pelanggan · dibuka di HP lewat link</div>
        <h1 class="text-xl font-semibold tracking-tight">Billboard</h1>
    </div>

    <div class="grid gap-4 lg:grid-cols-[1fr_380px]">
        <section class="space-y-4">
            <div class="kartu p-4 space-y-3">
                <div class="label">Link billboard</div>
                <p class="text-sm text-muted">
                    Pelanggan bisa melihat unit yang kosong, sisa waktu yang sedang main, tarif, booking hari ini, turnamen, dan menu F&amp;B — diperbarui otomatis.
                    Bagikan di bio Instagram, status WhatsApp, atau grup pelanggan.
                </p>
                <div class="rounded-md border border-line bg-bg px-3 py-2 text-sm break-all num">{{ $url }}</div>
                <div class="flex flex-wrap gap-2" x-data="{ tersalin: false }">
                    <button type="button" class="btn btn-primary h-10 px-4"
                            @click="navigator.clipboard.writeText(@js($url)).then(() => { tersalin = true; setTimeout(() => tersalin = false, 2000) })">
                        <span x-text="tersalin ? 'Tersalin ✓' : 'Salin link'"></span>
                    </button>
                    <a href="https://wa.me/?text={{ urlencode('Cek unit kosong & sisa waktu main sekarang: '.$url) }}" target="_blank" class="btn h-10 px-4">Bagikan ke WhatsApp</a>
                    <a href="{{ $url }}" target="_blank" class="btn h-10 px-4">Buka ↗</a>
                </div>
            </div>

            <div class="kartu p-4 space-y-3">
                <div class="label">Teks berjalan / pengumuman</div>
                <textarea wire:model="pengumuman" rows="3" class="input" maxlength="500" placeholder="Promo begadang 6 jam Rp50.000 mulai 23:00 · Turnamen FC 25 Sabtu ini!"></textarea>
                @error('pengumuman') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                <button type="button" wire:click="simpan" class="btn btn-primary h-10 px-4">Simpan</button>
            </div>

            <button type="button" wire:click="gantiKunci" wire:confirm="Ganti link billboard? Link lama yang sudah dibagikan tidak bisa dibuka lagi."
                    class="btn btn-ghost h-8 px-2 text-xs text-muted">Ganti link (jika link tersebar ke tempat yang tidak diinginkan)</button>
        </section>

        {{-- Pratinjau ukuran HP --}}
        <section class="kartu p-4">
            <div class="label mb-2">Pratinjau di HP</div>
            <div class="mx-auto rounded-[28px] border-4 border-line overflow-hidden bg-bg" style="width: 320px; height: 620px;" wire:ignore>
                <iframe src="{{ $url }}" title="Pratinjau billboard" loading="lazy" style="width: 100%; height: 100%; border: 0;"></iframe>
            </div>
        </section>
    </div>
</div>
