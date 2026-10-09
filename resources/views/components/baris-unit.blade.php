{{--
    Satu unit dalam tampilan DAFTAR (Rental PS / PC): ringkas satu baris, remote TV/PC dibuka dengan tombol.
    Isi status & timer sama dengan kartu-unit; slot = tombol aksi (partials/aksi-unit, ringkas).
--}}
@props([
    'unit',
    'sesi' => null,
    'aksesori' => null,
    'tv' => null,
    'bisaRemote' => false,
    'tarif' => null,
    'peringatanMenit' => 5,
    'serverNow',
])

@php
    [$labelStatus, $warnaStatus] = [
        'kosong' => [__('Ready'), 'var(--status-kosong)'],
        'main' => [__('Terisi'), 'var(--status-main)'],
        'pause' => [__('Dijeda'), 'var(--status-pause)'],
        'menunggu_bayar' => [__('Menunggu Bayar'), 'var(--status-hampir-habis)'],
        'servis' => [__('Maintenance'), 'var(--status-servis)'],
    ][$unit->status] ?? [ucfirst($unit->status), 'var(--border)'];

    $trx = $sesi?->transaksi;
    $aktif = $sesi && in_array($sesi->status, ['berjalan', 'dijeda'], true);
    $paket = $sesi?->mode === 'paket';
    $warnaUnit = $unit->warnaKartu();
    $alat = ($tv?->isPc() ?? $unit->isPc()) ? 'PC' : 'TV';
    $warnaTv = match (true) {
        $tv === null => null,
        ! $tv->isOnline() => 'var(--status-offline)',
        $tv->sedangBypass() => 'var(--status-hampir-habis)',
        default => 'var(--status-kosong)',
    };
@endphp

<div x-data="{ remote: false }" class="border-b border-line last:border-b-0" style="box-shadow: inset 4px 0 0 {{ $warnaUnit }}">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 pl-4 pr-3 py-2.5">
        {{-- Unit --}}
        <div class="w-28 shrink-0 min-w-0">
            <div class="flex items-center gap-1.5">
                <span class="font-semibold rounded px-1.5 -ml-1.5" style="color: {{ $warnaUnit }}; background: color-mix(in srgb, {{ $warnaUnit }} 16%, transparent);">{{ $unit->kode }}</span>
                @if ($warnaTv)
                    <button type="button" wire:click="$dispatch('buka-kelola-tv', { unitId: '{{ $unit->id }}' })"
                            class="dot" style="color: {{ $warnaTv }}" title="{{ $tv->isOnline() ? __(':alat online', ['alat' => $alat]) : __(':alat offline', ['alat' => $alat]) }}"></button>
                @endif
            </div>
            <div class="text-xs text-muted truncate">{{ $unit->tipeKonsol?->nama ?? $unit->tipeKonsol?->kode }}</div>
        </div>

        {{-- Status --}}
        <div class="w-32 shrink-0 label flex items-center gap-1.5" style="color: {{ $warnaStatus }};">
            <span class="dot"></span>{{ $labelStatus }}
        </div>

        {{-- Pemain / info --}}
        <div class="flex-1 min-w-[9rem] text-sm">
            @if ($aktif)
                <div class="font-medium truncate">{{ $trx?->pelanggan_nama ?: __('Tamu') }}</div>
                <div class="text-xs text-muted truncate">
                    {{ $paket ? ($sesi->paketHarga?->nama ?? __('Durasi :lama', ['lama' => \App\Models\Sesi::formatDurasi($sesi->durasi_menit * 60)])) : __('Open Billing') }}
                    @if ($aksesori && $aksesori->isNotEmpty()) · {{ $aksesori->map(fn ($s) => ($s->aksesori?->nama ?? '').($s->qty > 1 ? ' ×'.$s->qty : ''))->implode(', ') }}@endif
                </div>
            @elseif ($unit->status === 'menunggu_bayar' && $trx)
                <div class="font-medium truncate">{{ $trx->pelanggan_nama ?: __('Tamu') }}</div>
                <div class="text-xs text-muted num">{{ $trx->nomor }}</div>
            @elseif ($unit->status === 'servis')
                <span class="text-muted">{{ __('Dalam perbaikan') }}</span>
            @elseif ($tarif)
                <span class="text-muted">{{ __('Standby') }} ·</span> <x-rupiah :nilai="$tarif" class="text-accent" /> <span class="text-muted">/ {{ __('jam') }}</span>
            @else
                <span class="text-danger">{{ __('Tarif belum diatur') }}</span>
            @endif
        </div>

        {{-- Waktu --}}
        <div class="w-28 shrink-0">
            @if ($aktif)
                <div x-data="timerSesi({
                        mode: @js($sesi->mode),
                        mulai: {{ $sesi->mulai_pada->getTimestampMs() }},
                        berakhir: {{ $sesi->berakhir_pada?->getTimestampMs() ?? 'null' }},
                        dijeda: {{ $sesi->dijeda_pada?->getTimestampMs() ?? 'null' }},
                        jedaDetik: {{ (int) $sesi->total_jeda_detik + (int) $sesi->bonus_detik }},
                        peringatanMenit: {{ (int) $peringatanMenit }},
                        serverNow: {{ $serverNow }},
                     })">
                    <div class="num font-semibold" :class="{ 'text-st-hampir': hampir, 'text-danger': habis, 'text-st-main': pilihGame }" x-text="teks">--:--:--</div>
                    <div class="text-[11px] text-muted">
                        <span x-show="pilihGame" x-cloak>{{ __('pilih game') }}</span>
                        <span x-show="! pilihGame">
                            @if ($sesi->status === 'dijeda') {{ __('dijeda :jam', ['jam' => $sesi->dijeda_pada->format('H:i')]) }}
                            @elseif ($paket) {{ __('sisa · s/d :jam', ['jam' => $sesi->berakhir_pada->format('H:i')]) }}
                            @else {{ __('berjalan') }} @endif
                        </span>
                    </div>
                </div>
            @endif
        </div>

        {{-- Tagihan --}}
        <div class="w-24 shrink-0 text-right">
            @if (($trx?->total ?? 0) > 0)
                <x-rupiah :nilai="$trx->total" class="font-semibold {{ $unit->status === 'menunggu_bayar' ? '' : 'text-accent' }}" />
            @elseif ($aktif && ! $paket)
                <span class="text-[11px] text-muted">{{ __('saat selesai') }}</span>
            @endif
        </div>

        {{-- Aksi --}}
        <div class="flex items-center gap-1.5 ml-auto">
            {{ $slot }}
            @if ($tv)
                <button type="button" @click="remote = ! remote" title="{{ __('Remote :alat', ['alat' => $alat]) }}"
                        class="btn btn-ikon h-9 w-9" :class="remote && 'text-accent border-accent'">
                    <x-ikon name="{{ $alat === 'PC' ? 'pc' : 'kelola' }}" size="16" />
                </button>
            @endif
        </div>
    </div>

    @if ($tv)
        <div x-show="remote" x-cloak x-collapse>
            <x-remote-unit :unit="$unit" :tv="$tv" :bisa-remote="$bisaRemote" bingkai="px-3 pb-2.5 justify-end flex-wrap" />
        </div>
    @endif
</div>
