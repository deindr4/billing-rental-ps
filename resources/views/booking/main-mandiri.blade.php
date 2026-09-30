@php
    $u = $this->unit;
    $k = $this->konteks;
    $t = $this->tagihan;
    $rp = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
    $layanan = app(\App\Services\Gateway\BayarMandiriService::class);
@endphp
<div class="max-w-md mx-auto px-4 py-6 space-y-5" @if ($t && in_array($t->status, ['menunggu', 'dibayar'], true)) wire:poll.3s @endif>
    <header class="flex items-center gap-3">
        @if ($this->logo())
            <img src="{{ $this->logo() }}" alt="" class="h-11 w-11 rounded-lg object-contain">
        @endif
        <div class="min-w-0">
            <div class="label">{{ $u->cabang?->tenant?->nama }} · {{ $u->cabang?->nama }}</div>
            <h1 class="text-xl font-bold leading-tight">{{ $u->nama }}</h1>
            <div class="text-sm text-muted">{{ $u->tipeKonsol?->nama }}{{ $this->tarif ? ' · '.$rp($this->tarif).'/jam' : '' }}</div>
        </div>
    </header>

    {{-- ================= Status tagihan ================= --}}
    @if ($t && $t->status === 'selesai')
        <section class="kartu p-6 text-center space-y-2" style="border-color: var(--accent)">
            <div class="text-4xl">🎮</div>
            <div class="text-xl font-bold" style="color: var(--accent)">Pembayaran berhasil!</div>
            <p>TV terbuka. Selamat bermain <b>{{ $layanan->labelMenit($t->menit) }}</b>.</p>
            @if ($t->jenis === 'mulai') <p class="text-sm text-muted">Ada waktu pilih game sebelum hitungan dimulai.</p> @endif
        </section>
    @elseif ($t && $t->status === 'perlu_tindakan')
        <section class="kartu p-5 text-center space-y-2" style="border-color: var(--status-hampir-habis)">
            <div class="text-lg font-bold">Pembayaran diterima</div>
            <p class="text-sm">{{ $t->catatan }} Silakan ke kasir, tunjukkan halaman ini.</p>
            <p class="text-xs text-muted num">{{ $t->merchant_ref }}</p>
        </section>
    @elseif ($t && $t->masihBisaDibayar())
        <section class="kartu p-5 text-center space-y-3">
            <div class="label">{{ $t->urlBayar() ? 'Selesaikan pembayaran' : 'Scan QRIS di layar TV' }}</div>
            <div class="text-3xl font-bold num">{{ $rp($t->nominal) }}</div>
            <div class="text-sm">= <b>{{ $layanan->labelMenit($t->menit) }}</b> main di {{ $u->nama }}</div>
            @if ($t->urlBayar())
                <a href="{{ $t->urlBayar() }}" target="_blank" rel="noopener" class="btn btn-primary w-full h-12 text-base">Bayar sekarang</a>
                <p class="text-sm text-muted">Pilih metode (QRIS, e-wallet, transfer) di halaman pembayaran. Kembali ke halaman ini — TV terbuka otomatis setelah lunas.</p>
            @else
                <p class="text-sm text-muted">Buka aplikasi bank / e-wallet → Scan QR → arahkan ke TV. Nominal sudah terisi otomatis.</p>
            @endif
            <div class="flex items-center justify-center gap-2 text-sm text-muted">
                <span class="dot animate-pulse" style="color: var(--accent)"></span> Menunggu pembayaran…
            </div>
            <div class="text-xs text-muted" x-data="{ s: 0, init() { const f = () => this.s = Math.max(0, Math.round(({{ $t->kedaluwarsa_pada->getTimestampMs() }} - Date.now()) / 1000)); f(); setInterval(f, 1000) } }">
                Berlaku <span class="num" x-text="Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0')"></span>
            </div>
            <button type="button" wire:click="ganti" class="btn btn-ghost h-9 text-sm">Ganti nominal</button>
        </section>
    @elseif (! $k['bisa'])
        <section class="kartu p-6 text-center space-y-1">
            <div class="text-lg font-semibold">{{ $k['alasan'] }}</div>
            <p class="text-sm text-muted">Silakan ke kasir.</p>
        </section>
    @else
        {{-- ================= Pilih nominal ================= --}}
        <form wire:submit="tampilkan" class="space-y-4">
            <div class="kartu p-4 space-y-1">
                <div class="font-semibold">{{ $k['jenis'] === 'isi_ulang' ? 'Waktu habis — isi ulang' : 'Mau main berapa lama?' }}</div>
                <p class="text-sm text-muted">Ketik nominal yang ingin dibayar, waktu main dihitung otomatis dari tarif {{ $this->tarif ? $rp($this->tarif).'/jam' : '' }}. Minimal {{ $this->minimal }} menit.</p>
            </div>

            <div class="grid grid-cols-3 gap-2">
                @foreach ($this->pilihan as $p)
                    <button type="button" wire:click="pilih({{ $p['nominal'] }})"
                            @class(['rounded-lg border px-2 py-2.5 text-center', 'border-accent' => (int) $nominal === $p['nominal'], 'border-line' => (int) $nominal !== $p['nominal']])>
                        <span class="block font-semibold text-sm">{{ $p['label'] }}</span>
                        <span class="block text-xs text-muted num">{{ $rp($p['nominal']) }}</span>
                    </button>
                @endforeach
            </div>

            <div>
                <label class="block text-sm mb-1.5">Atau ketik nominal</label>
                <x-input-uang wire:model.live.debounce.400ms="nominal" class="text-xl" />
                @if ($perkiraan = $this->perkiraan())
                    <p class="text-sm mt-2">= <b style="color: var(--accent)">{{ $perkiraan }}</b></p>
                @endif
                @error('nominal') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="btn btn-primary w-full h-12 text-base" wire:loading.attr="disabled" wire:target="tampilkan">
                <span wire:loading.remove wire:target="tampilkan">Tampilkan QRIS di TV</span>
                <span wire:loading wire:target="tampilkan">Membuat QRIS…</span>
            </button>
            <p class="text-xs text-muted text-center">TV terbuka otomatis setelah pembayaran berhasil.</p>
        </form>
    @endif
</div>
