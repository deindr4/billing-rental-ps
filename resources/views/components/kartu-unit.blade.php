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
    $totalDurasi = $paket && $sesi->durasi_menit
        ? sprintf('%02d:%02d', intdiv($sesi->durasi_menit, 60), $sesi->durasi_menit % 60)
        : null;

    // Indikator TV Agent / agen PC: [teks, warna, keterangan]
    $alat = ($tv?->isPc() ?? $unit->isPc()) ? 'PC' : 'TV';
    $indikatorTv = match (true) {
        $tv === null && $unit->pakaiTvAgent() => [__(':alat belum dipasang', ['alat' => $alat]), 'var(--text-muted)', __('Pasangkan :alat di Admin → Perangkat TV & PC', ['alat' => $alat])],
        $tv === null => null,
        ! $tv->isOnline() => [__(':alat offline', ['alat' => $alat]), 'var(--status-offline)', $tv->terakhir_online ? __('Terakhir terlihat :waktu', ['waktu' => $tv->terakhir_online->diffForHumans()]) : __('Belum pernah tersambung')],
        $tv->sedangBypass() => [__(':alat bypass s/d :jam', ['alat' => $alat, 'jam' => $tv->bypass_sampai->format('H:i')]), 'var(--status-hampir-habis)', __(':alat terbuka sementara tanpa sesi', ['alat' => $alat])],
        default => [__(':alat online', ['alat' => $alat]), 'var(--status-kosong)', $alat === 'PC' ? __('Agen kiosk PC tersambung') : __('TV Agent tersambung')],
    };
@endphp

@php $warnaUnit = $unit->warnaKartu(); @endphp
{{-- Garis atas = warna status (tetap); strip kiri & chip kode = warna penanda unit (Admin → Unit → Warna kartu) --}}
<div class="kartu kartu-status flex flex-col" style="--warna-status: {{ $warnaStatus }}; border-left: 4px solid {{ $warnaUnit }};">
    {{-- Kepala --}}
    <div class="px-4 pt-3.5 pb-2.5 flex items-start justify-between gap-2">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <span class="text-lg font-semibold tracking-tight rounded px-1.5 -ml-1.5"
                      style="color: {{ $warnaUnit }}; background: color-mix(in srgb, {{ $warnaUnit }} 16%, transparent);">{{ $unit->kode }}</span>
                @if ($unit->kategori)
                    <span class="chip">{{ $unit->kategori->nama }}</span>
                @endif
            </div>
            <div class="text-xs text-muted truncate">
                {{ $unit->tipeKonsol?->nama ?? $unit->tipeKonsol?->kode }}{{ $unit->lokasi ? ' · '.$unit->lokasi : '' }}
            </div>
            @if ($indikatorTv)
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mt-1">
                    <button type="button"
                            wire:click="$dispatch('buka-kelola-tv', { unitId: '{{ $unit->id }}' })"
                            class="label flex items-center gap-1.5 hover:underline" style="color: {{ $indikatorTv[1] }};" title="{{ $indikatorTv[2] }}">
                        <span class="dot"></span>{{ $indikatorTv[0] }}
                    </button>
                    {{-- HDMI yang dipakai (TV dengan beberapa konsol): klik untuk pindah --}}
                    @if ($tv && count($tv->daftarInput()) > 1)
                        <button type="button" wire:click="$dispatch('buka-pilih-hdmi', { unitId: '{{ $unit->id }}' })"
                                class="label flex items-center gap-1 rounded px-1.5 py-0.5 hover:underline"
                                style="color: var(--ikon-biru); background: color-mix(in srgb, var(--ikon-biru) 12%, transparent);"
                                title="{{ __('Pilih / pindah HDMI') }}">
                            {{ $tv->input_hdmi ? $tv->labelHdmi($tv->input_hdmi) : __('Pilih HDMI') }}
                            <x-ikon name="chevron" size="12" />
                        </button>
                    @endif
                </div>
            @endif
        </div>
        <span class="label flex items-center gap-1.5 shrink-0 pt-1" style="color: {{ $warnaStatus }};">
            <span class="dot"></span>{{ $labelStatus }}
        </span>
    </div>

    <div class="px-4 pb-3 flex-1">
        @if ($aktif)
            {{-- Sesi berjalan / dijeda --}}
            <div x-data="timerSesi({
                    mode: @js($sesi->mode),
                    mulai: {{ $sesi->mulai_pada->getTimestampMs() }},
                    berakhir: {{ $sesi->berakhir_pada?->getTimestampMs() ?? 'null' }},
                    dijeda: {{ $sesi->dijeda_pada?->getTimestampMs() ?? 'null' }},
                    jedaDetik: {{ (int) $sesi->total_jeda_detik + (int) $sesi->bonus_detik }}, {{-- bonus waktu tidak ditagih --}}
                    peringatanMenit: {{ (int) $peringatanMenit }},
                    serverNow: {{ $serverNow }},
                 })"
                 class="rounded-md border border-line bg-bg px-3 py-2.5"
                 :class="{ 'border-st-hampir': hampir, 'border-danger': habis }">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="label" :class="{ 'text-st-hampir': hampir, 'text-danger': habis, 'text-st-main': pilihGame }">
                            <span x-show="pilihGame" x-cloak>{{ __('Pilih game · belum ditagih') }}</span>
                            <span x-show="! pilihGame && ! hampir && ! habis">{{ $paket ? __('Sisa waktu') : __('Durasi berjalan') }}</span>
                            <span x-show="hampir" x-cloak>{{ __('Segera habis') }}</span>
                            <span x-show="habis" x-cloak>{{ __('Waktu habis') }}</span>
                        </div>
                        <div class="num text-[28px] font-semibold leading-none mt-1"
                             :class="{ 'text-st-hampir': hampir, 'text-danger': habis, 'text-st-main': pilihGame }">
                            <span x-text="teks">--:--:--</span>@if ($totalDurasi)<span class="text-sm text-muted font-normal"> / {{ $totalDurasi }}</span>@endif
                        </div>
                        <div class="label mt-1.5">
                            @if ($sesi->status === 'dijeda')
                                {{ __('Dijeda :jam', ['jam' => $sesi->dijeda_pada->format('H:i')]) }}
                            @elseif ($paket)
                                {{ __('Selesai :jam', ['jam' => $sesi->berakhir_pada->format('H:i')]) }}
                            @else
                                {{ __('Open billing') }}
                            @endif
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <div class="label">{{ __('Tagihan') }}</div>
                        @if ($paket || ($trx?->total ?? 0) > 0)
                            <x-rupiah :nilai="$trx?->total" class="font-semibold text-accent block mt-1" />
                        @endif
                        @unless ($paket)
                            <div class="text-[11px] text-muted mt-0.5">{{ ($trx?->total ?? 0) > 0 ? __('+ sewa') : __('saat selesai') }}</div>
                        @endunless
                    </div>
                </div>
            </div>

            <dl class="text-sm mt-3 space-y-1">
                <div class="flex justify-between gap-2">
                    <dt class="text-muted">{{ __('Pemain') }}</dt>
                    <dd class="truncate font-medium">{{ $trx?->pelanggan_nama ?: __('Tamu') }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-muted">{{ __('Paket') }}</dt>
                    <dd class="truncate">{{ $paket ? ($sesi->paketHarga?->nama ?? __('Durasi :lama', ['lama' => \App\Models\Sesi::formatDurasi($sesi->durasi_menit * 60)])) : __('Open Billing') }}</dd>
                </div>
                @if ($aksesori && $aksesori->isNotEmpty())
                    <div class="flex justify-between gap-2">
                        <dt class="text-muted flex items-center gap-1"><x-ikon name="aksesori" size="13" class="text-ik-ungu" /> {{ __('Aksesori') }}</dt>
                        <dd class="truncate">{{ $aksesori->map(fn ($s) => ($s->aksesori?->nama ?? '').($s->qty > 1 ? ' ×'.$s->qty : ''))->implode(', ') }}</dd>
                    </div>
                @endif
            </dl>

        @elseif ($unit->status === 'menunggu_bayar' && $trx)
            {{-- Selesai, belum dibayar --}}
            <div class="rounded-md border border-st-hampir bg-bg px-3 py-3">
                <div class="label">{{ __('Total tagihan') }}</div>
                <x-rupiah :nilai="$trx->total" class="text-2xl font-semibold block mt-1" />
                <div class="text-xs text-muted mt-1 truncate">{{ $trx->pelanggan_nama ?: __('Tamu') }} · <span class="num">{{ $trx->nomor }}</span></div>
            </div>

        @elseif ($unit->status === 'servis')
            <div class="rounded-md border border-dashed border-line px-3 py-4 text-center">
                <div class="label" style="color: var(--status-servis);">{{ __('Dalam perbaikan') }}</div>
                <div class="text-xs text-muted mt-1">{{ __('Unit tidak bisa dipakai') }}</div>
            </div>

        @else
            {{-- Kosong --}}
            <div class="rounded-md border border-dashed border-line px-3 py-4 text-center">
                <x-ikon name="rental" size="22" class="mx-auto text-muted" />
                <div class="label mt-1.5">{{ __('Unit bersih & standby') }}</div>
                <div class="text-sm mt-1">
                    @if ($tarif)
                        <x-rupiah :nilai="$tarif" class="text-accent font-semibold" /> <span class="text-muted">/ {{ __('jam') }}</span>
                    @else
                        <span class="text-danger">{{ __('Tarif belum diatur') }}</span>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- Remote TV / PC (komponen bersama dengan tampilan daftar) --}}
    <x-remote-unit :unit="$unit" :tv="$tv" :bisa-remote="$bisaRemote" />

    {{-- Aksi --}}
    <div class="px-3 py-2.5 border-t border-line">
        {{ $slot }}
    </div>
</div>
