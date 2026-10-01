@php
    $t = $this->turnamen;
    $juara = $this->juara();
@endphp
<div class="max-w-2xl mx-auto px-4 py-6 space-y-5" wire:poll.30s>
    <header class="flex items-center gap-3">
        @if ($this->logo())
            <img src="{{ $this->logo() }}" alt="" class="h-12 w-12 rounded-lg object-contain">
        @endif
        <div class="min-w-0">
            <div class="label">{{ $t->cabang?->tenant?->nama }} · {{ $t->cabang?->nama }}</div>
            <h1 class="text-2xl font-bold leading-tight">{{ $t->nama }}</h1>
        </div>
    </header>

    <section class="kartu p-4 grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
        <div><div class="label">Game</div><div class="font-semibold">{{ $t->game }}</div></div>
        <div><div class="label">Mulai</div><div class="font-semibold">{{ $t->mulai_pada->translatedFormat('D, d M H:i') }}</div></div>
        <div>
            <div class="label">Biaya daftar</div>
            <div class="font-semibold">{{ $t->biaya_daftar > 0 ? 'Rp '.number_format($t->biaya_daftar, 0, ',', '.') : 'Gratis' }}</div>
            @if ($t->labelBonus()) <div class="text-xs" style="color: var(--accent)">+ {{ $t->labelBonus() }} gratis</div> @endif
        </div>
        <div><div class="label">Peserta</div><div class="font-semibold num">{{ $this->peserta->count() }}/{{ $t->kuota }}</div></div>
        <div class="col-span-2 sm:col-span-2"><div class="label">Format</div><div class="font-semibold">{{ $t->namaFormat() }}{{ $t->format === 'grup_gugur' ? " ({$t->jumlah_grup} grup)" : '' }}</div></div>
        @if ($t->total_hadiah > 0)
            <div class="col-span-2 sm:col-span-2"><div class="label">Total hadiah</div><div class="font-semibold text-accent">Rp {{ number_format($t->total_hadiah, 0, ',', '.') }}</div></div>
        @endif
    </section>

    @if ($juara)
        <section class="kartu p-4 space-y-1" style="border-color: var(--accent)">
            @foreach ($juara as $n => $nama)
                <div class="text-lg">🏆 Juara {{ $n }}: <b>{{ $nama }}</b></div>
            @endforeach
        </section>
    @endif

    @if ($t->hadiah)
        <section class="kartu p-4"><div class="label mb-1">Hadiah</div><p class="whitespace-pre-line">{{ $t->hadiah }}</p></section>
    @endif

    {{-- Pendaftaran --}}
    @if ($terdaftar)
        <section class="kartu p-4" style="border-color: var(--accent)">
            <b>{{ $terdaftar }}</b> terdaftar!
            @if ($t->biaya_daftar > 0)
                Bayar biaya pendaftaran di kasir sebelum turnamen dimulai supaya masuk bagan.
            @endif
        </section>
    @elseif ($t->bisaDaftarOnline())
        <form wire:submit="daftar" class="kartu p-4 space-y-3">
            <div class="font-semibold">Daftar sekarang</div>
            <input type="text" wire:model="nama" class="input" placeholder="Nama / gamer tag" maxlength="100">
            @error('nama') <p class="text-sm text-danger">{{ $message }}</p> @enderror
            <input type="tel" inputmode="tel" wire:model="telepon" class="input num" placeholder="Nomor WhatsApp" maxlength="20">
            @error('telepon') <p class="text-sm text-danger">{{ $message }}</p> @enderror
            <input type="text" wire:model="situs" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">
            <button type="submit" class="btn btn-primary w-full h-11" wire:loading.attr="disabled" wire:target="daftar">Daftar</button>
        </form>
    @elseif ($t->status === 'pendaftaran')
        <section class="kartu p-4 text-sm text-muted">{{ $t->penuh() ? 'Kuota penuh.' : 'Pendaftaran di kasir.' }}</section>
    @endif

    {{-- Bagan / klasemen (semua format) --}}
    @if ($this->bagian !== [])
        <div class="space-y-5">
            @include('turnamen.bagian', ['bagian' => $this->bagian, 'ubah' => false, 'units' => collect()])
        </div>
    @elseif ($this->peserta->isNotEmpty())
        <section class="kartu divide-y divide-line">
            <div class="px-4 py-2 label">Peserta terdaftar</div>
            @foreach ($this->peserta as $i => $p)
                <div class="px-4 py-2 text-sm flex justify-between">
                    <span>{{ $i + 1 }}. {{ $p->nama }}</span>
                    @if ($t->biaya_daftar > 0)
                        <span class="text-xs {{ $p->status === 'lunas' ? 'text-accent' : 'text-muted' }}">{{ $p->status === 'lunas' ? 'Lunas' : 'Belum bayar' }}</span>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    @if ($t->aturan)
        <section class="kartu p-4"><div class="label mb-1">Aturan</div><p class="text-sm whitespace-pre-line">{{ $t->aturan }}</p></section>
    @endif
</div>
