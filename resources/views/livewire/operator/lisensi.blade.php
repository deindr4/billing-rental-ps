<div class="max-w-3xl mx-auto space-y-4">
    <div>
        <div class="label">{{ __('Perangkat lunak gratis & terbuka') }}</div>
        <h1 class="text-xl font-semibold tracking-tight">{{ __('Lisensi MIT') }}</h1>
        <p class="text-sm text-muted">
            Delta Billing HuB{{ $versi ? ' · v'.$versi : '' }} · Copyright © deindr4
        </p>
    </div>

    {{-- Kontak & komunitas: kartu + tombol berwarna merek supaya jelas bisa diklik --}}
    @include('partials.kontak-pengembang')

    {{-- Ringkasan --}}
    <div class="kartu p-4 text-sm space-y-2">
        <div class="font-semibold">{{ __('Ringkasan') }}</div>
        @include('partials.ringkasan-lisensi')
    </div>

    {{-- Teks lisensi lengkap --}}
    <details class="kartu" open>
        <summary class="px-4 py-3 cursor-pointer select-none font-semibold">{{ __('Teks lisensi lengkap') }}</summary>
        <pre class="px-4 pb-4 text-xs leading-relaxed whitespace-pre-wrap break-words font-mono text-muted">{{ $teks }}</pre>
    </details>
</div>
