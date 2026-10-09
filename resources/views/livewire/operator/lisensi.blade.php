@php $p = \App\Support\Pengembang::class; @endphp
<div class="max-w-3xl mx-auto space-y-4">
    <div>
        <div class="label">{{ __('Perangkat lunak gratis & terbuka') }}</div>
        <h1 class="text-xl font-semibold tracking-tight">{{ __('Lisensi MIT') }}</h1>
        <p class="text-sm text-muted">
            Billing PS{{ $versi ? ' · v'.$versi : '' }} · Copyright © deindr4
        </p>
    </div>

    {{-- Kontak & komunitas: kartu + tombol berwarna merek supaya jelas bisa diklik --}}
    @php
        $kontak = [
            [
                'url' => $p::TELEGRAM, 'warna' => '#229ED9', 'judul' => 'Telegram', 'nama' => '@deindr4',
                'ket' => __('Kontak pengembang'), 'tombol' => __('Chat di Telegram'),
                'ikon' => '<path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/>',
            ],
            [
                'url' => $p::GRUP_WA, 'warna' => '#25D366', 'judul' => __('Grup WhatsApp'), 'nama' => __('Dev Billing PS'),
                'ket' => __('Grup bertujuan untuk info pengembangan'), 'tombol' => __('Gabung grup WhatsApp'),
                'ikon' => '<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/>',
            ],
            [
                'url' => $p::REPO, 'warna' => '#6e7681', 'judul' => 'GitHub', 'nama' => 'deindr4/billing-rental-ps',
                'ket' => __('Kode sumber, rilis & panduan'), 'tombol' => __('Buka GitHub'),
                'ikon' => '<path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/>',
            ],
        ];
    @endphp
    <div class="grid gap-3 sm:grid-cols-3">
        @foreach ($kontak as $k)
            <div class="kartu p-4 flex flex-col gap-3" style="border-top: 3px solid {{ $k['warna'] }}">
                <div class="flex items-center gap-3">
                    <span class="h-11 w-11 shrink-0 rounded-full grid place-items-center text-white" style="background: {{ $k['warna'] }}">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" fill="currentColor" aria-hidden="true">{!! $k['ikon'] !!}</svg>
                    </span>
                    <div class="min-w-0">
                        <div class="label" style="color: {{ $k['warna'] }}">{{ $k['judul'] }}</div>
                        <div class="font-semibold truncate">{{ $k['nama'] }}</div>
                    </div>
                </div>
                <p class="text-xs text-muted flex-1">{{ $k['ket'] }}</p>
                <a href="{{ $k['url'] }}" target="_blank" rel="noopener"
                   class="btn h-10 w-full justify-center gap-2 font-semibold text-white hover:brightness-110"
                   style="background: {{ $k['warna'] }}; border-color: {{ $k['warna'] }}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true">{!! $k['ikon'] !!}</svg>
                    {{ $k['tombol'] }} ↗
                </a>
            </div>
        @endforeach
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
