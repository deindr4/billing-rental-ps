@php $p = \App\Support\Pengembang::class; @endphp
<div class="max-w-3xl mx-auto space-y-4">
    <div>
        <div class="label">{{ __('Perangkat lunak gratis & terbuka') }}</div>
        <h1 class="text-xl font-semibold tracking-tight">{{ __('Lisensi MIT') }}</h1>
        <p class="text-sm text-muted">
            Billing PS{{ $versi ? ' · v'.$versi : '' }} · Copyright © deindr4
        </p>
    </div>

    {{-- Kontak & komunitas --}}
    <div class="grid gap-3 sm:grid-cols-3">
        <a href="{{ $p::TELEGRAM }}" target="_blank" rel="noopener" class="kartu p-4 hover:border-accent">
            <div class="label" style="color: var(--ikon-biru)">Telegram</div>
            <div class="font-semibold mt-1">@deindr4</div>
            <div class="text-xs text-muted mt-1">{{ __('Kontak pengembang') }}</div>
        </a>
        <a href="{{ $p::GRUP_WA }}" target="_blank" rel="noopener" class="kartu p-4 hover:border-accent">
            <div class="label" style="color: var(--ikon-hijau)">{{ __('Grup WhatsApp') }}</div>
            <div class="font-semibold mt-1">{{ __('Dev Billing PS') }}</div>
            <div class="text-xs text-muted mt-1">{{ __('Grup bertujuan untuk info pengembangan') }}</div>
        </a>
        <a href="{{ $p::REPO }}" target="_blank" rel="noopener" class="kartu p-4 hover:border-accent">
            <div class="label">GitHub</div>
            <div class="font-semibold mt-1">deindr4/billing-rental-ps</div>
            <div class="text-xs text-muted mt-1">{{ __('Kode sumber, rilis & panduan') }}</div>
        </a>
    </div>

    {{-- Ringkasan --}}
    <div class="kartu p-4 text-sm space-y-2">
        <div class="font-semibold">{{ __('Ringkasan') }}</div>
        <ul class="list-disc pl-5 space-y-1 text-muted">
            <li>{{ __('Aplikasi ini gratis: boleh dipakai, disalin, diubah, dan dibagikan, termasuk untuk usaha rental.') }}</li>
            <li>{{ __('Tulisan "Copyright © deindr4" di kaki aplikasi wajib tetap tampil dan tidak diubah.') }}</li>
            <li>{{ __('Anda boleh menambahkan nama / merek Anda sendiri di sebelahnya (Admin → Pengaturan Tampilan → Kaki aplikasi).') }}</li>
            <li>{{ __('Aplikasi diberikan apa adanya, tanpa jaminan apa pun.') }}</li>
        </ul>
    </div>

    {{-- Teks lisensi lengkap --}}
    <details class="kartu" open>
        <summary class="px-4 py-3 cursor-pointer select-none font-semibold">{{ __('Teks lisensi lengkap') }}</summary>
        <pre class="px-4 pb-4 text-xs leading-relaxed whitespace-pre-wrap break-words font-mono text-muted">{{ $teks }}</pre>
    </details>
</div>
