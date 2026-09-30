@props([
    'unit',
    'sesi' => null,
    'tv' => null,
    'bisaRemote' => false,
    'tarif' => null,
    'peringatanMenit' => 5,
    'serverNow',
])

@php
    [$labelStatus, $warnaStatus] = [
        'kosong' => ['Ready', 'var(--status-kosong)'],
        'main' => ['Terisi', 'var(--status-main)'],
        'pause' => ['Dijeda', 'var(--status-pause)'],
        'menunggu_bayar' => ['Menunggu Bayar', 'var(--status-hampir-habis)'],
        'servis' => ['Maintenance', 'var(--status-servis)'],
    ][$unit->status] ?? [ucfirst($unit->status), 'var(--border)'];

    $trx = $sesi?->transaksi;
    $aktif = $sesi && in_array($sesi->status, ['berjalan', 'dijeda'], true);
    $paket = $sesi?->mode === 'paket';
    $totalDurasi = $paket && $sesi->durasi_menit
        ? sprintf('%02d:%02d', intdiv($sesi->durasi_menit, 60), $sesi->durasi_menit % 60)
        : null;

    // Indikator TV Agent: [teks, warna, keterangan]
    $indikatorTv = match (true) {
        $tv === null && $unit->pakaiTvAgent() => ['TV belum dipasang', 'var(--text-muted)', 'Pasangkan TV di Admin → Perangkat TV'],
        $tv === null => null,
        ! $tv->isOnline() => ['TV offline', 'var(--status-offline)', $tv->terakhir_online ? 'Terakhir terlihat '.$tv->terakhir_online->diffForHumans() : 'Belum pernah tersambung'],
        $tv->sedangBypass() => ['TV bypass s/d '.$tv->bypass_sampai->format('H:i'), 'var(--status-hampir-habis)', 'TV terbuka sementara tanpa sesi'],
        default => ['TV online', 'var(--status-kosong)', 'TV Agent tersambung'],
    };
@endphp

<div class="kartu kartu-status flex flex-col" style="--warna-status: {{ $warnaStatus }};">
    {{-- Kepala --}}
    <div class="px-4 pt-3.5 pb-2.5 flex items-start justify-between gap-2">
        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <span class="text-lg font-semibold tracking-tight">{{ $unit->kode }}</span>
                @if ($unit->kategori)
                    <span class="chip">{{ $unit->kategori->nama }}</span>
                @endif
            </div>
            <div class="text-xs text-muted truncate">
                {{ $unit->tipeKonsol?->nama ?? $unit->tipeKonsol?->kode }}{{ $unit->lokasi ? ' · '.$unit->lokasi : '' }}
            </div>
            @if ($indikatorTv)
                <button type="button"
                        wire:click="$dispatch('buka-kelola-tv', { unitId: '{{ $unit->id }}' })"
                        class="label flex items-center gap-1.5 mt-1 hover:underline" style="color: {{ $indikatorTv[1] }};" title="{{ $indikatorTv[2] }}">
                    <span class="dot"></span>{{ $indikatorTv[0] }}
                </button>
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
                    jedaDetik: {{ (int) $sesi->total_jeda_detik }},
                    peringatanMenit: {{ (int) $peringatanMenit }},
                    serverNow: {{ $serverNow }},
                 })"
                 class="rounded-md border border-line bg-bg px-3 py-2.5"
                 :class="{ 'border-st-hampir': hampir, 'border-danger': habis }">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="label" :class="{ 'text-st-hampir': hampir, 'text-danger': habis, 'text-st-main': pilihGame }">
                            <span x-show="pilihGame" x-cloak>Pilih game · belum ditagih</span>
                            <span x-show="! pilihGame && ! hampir && ! habis">{{ $paket ? 'Sisa waktu' : 'Durasi berjalan' }}</span>
                            <span x-show="hampir" x-cloak>Segera habis</span>
                            <span x-show="habis" x-cloak>Waktu habis</span>
                        </div>
                        <div class="num text-[28px] font-semibold leading-none mt-1"
                             :class="{ 'text-st-hampir': hampir, 'text-danger': habis, 'text-st-main': pilihGame }">
                            <span x-text="teks">--:--:--</span>@if ($totalDurasi)<span class="text-sm text-muted font-normal"> / {{ $totalDurasi }}</span>@endif
                        </div>
                        <div class="label mt-1.5">
                            @if ($sesi->status === 'dijeda')
                                Dijeda {{ $sesi->dijeda_pada->format('H:i') }}
                            @elseif ($paket)
                                Selesai {{ $sesi->berakhir_pada->format('H:i') }}
                            @else
                                Open billing
                            @endif
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <div class="label">Tagihan</div>
                        @if ($paket || ($trx?->total ?? 0) > 0)
                            <x-rupiah :nilai="$trx?->total" class="font-semibold text-accent block mt-1" />
                        @endif
                        @unless ($paket)
                            <div class="text-[11px] text-muted mt-0.5">{{ ($trx?->total ?? 0) > 0 ? '+ sewa' : 'saat selesai' }}</div>
                        @endunless
                    </div>
                </div>
            </div>

            <dl class="text-sm mt-3 space-y-1">
                <div class="flex justify-between gap-2">
                    <dt class="text-muted">Pemain</dt>
                    <dd class="truncate font-medium">{{ $trx?->pelanggan_nama ?: 'Tamu' }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-muted">Paket</dt>
                    <dd class="truncate">{{ $paket ? ($sesi->paketHarga?->nama ?? 'Durasi '.\App\Models\Sesi::formatDurasi($sesi->durasi_menit * 60)) : 'Open Billing' }}</dd>
                </div>
            </dl>

        @elseif ($unit->status === 'menunggu_bayar' && $trx)
            {{-- Selesai, belum dibayar --}}
            <div class="rounded-md border border-st-hampir bg-bg px-3 py-3">
                <div class="label">Total tagihan</div>
                <x-rupiah :nilai="$trx->total" class="text-2xl font-semibold block mt-1" />
                <div class="text-xs text-muted mt-1 truncate">{{ $trx->pelanggan_nama ?: 'Tamu' }} · <span class="num">{{ $trx->nomor }}</span></div>
            </div>

        @elseif ($unit->status === 'servis')
            <div class="rounded-md border border-dashed border-line px-3 py-4 text-center">
                <div class="label" style="color: var(--status-servis);">Dalam perbaikan</div>
                <div class="text-xs text-muted mt-1">Unit tidak bisa dipakai</div>
            </div>

        @else
            {{-- Kosong --}}
            <div class="rounded-md border border-dashed border-line px-3 py-4 text-center">
                <x-ikon name="rental" size="22" class="mx-auto text-muted" />
                <div class="label mt-1.5">Unit bersih & standby</div>
                <div class="text-sm mt-1">
                    @if ($tarif)
                        <x-rupiah :nilai="$tarif" class="text-accent font-semibold" /> <span class="text-muted">/ jam</span>
                    @else
                        <span class="text-danger">Tarif belum diatur</span>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- Remote TV (hanya jika TV Agent terhubung & online) --}}
    @if ($tv && $tv->isOnline())
        @php
            $layarMati = $tv->layar_hidup === false;
            $bisaReboot = (bool) ($tv->diagnostik['device_owner'] ?? false);
        @endphp
        <div class="px-3 py-2 border-t border-line flex items-center gap-1.5">
            <span class="label mr-auto">TV</span>

            @if ($bisaRemote)
                @if ($layarMati)
                    <button type="button" title="Nyalakan layar TV"
                            wire:click="perintahTv('{{ $unit->id }}', 'layar_nyala')"
                            class="btn btn-ikon h-8 w-8 text-st-kosong">
                        <x-ikon name="daya" size="16" />
                    </button>
                @else
                    <x-confirm-button action="perintahTv" :params="[$unit->id, 'layar_mati']"
                                      title="Matikan layar {{ $unit->nama }}?"
                                      text="TV masuk mode standby. Nyalakan lagi dari tombol yang sama atau remote TV."
                                      confirm-text="Matikan"
                                      class="btn-ikon h-8 w-8 text-muted" title="Matikan layar TV">
                        <x-ikon name="daya" size="16" />
                    </x-confirm-button>
                @endif
            @endif

            <button type="button" title="Volume turun" wire:click="perintahTv('{{ $unit->id }}', 'volume_turun')"
                    class="btn btn-ikon h-8 w-8 text-muted">
                <x-ikon name="vol-turun" size="16" />
            </button>
            <span class="num text-xs w-9 text-center {{ $tv->senyap ? 'text-danger' : 'text-muted' }}" title="Volume TV">
                {{ $tv->senyap ? 'MUTE' : ($tv->volume !== null ? $tv->volume.'%' : '–') }}
            </span>
            <button type="button" title="Volume naik" wire:click="perintahTv('{{ $unit->id }}', 'volume_naik')"
                    class="btn btn-ikon h-8 w-8 text-muted">
                <x-ikon name="vol-naik" size="16" />
            </button>
            <button type="button" title="Senyap / bunyikan" wire:click="perintahTv('{{ $unit->id }}', 'volume_senyap')"
                    @class(['btn btn-ikon h-8 w-8', 'text-danger' => $tv->senyap, 'text-muted' => ! $tv->senyap])>
                <x-ikon name="senyap" size="16" />
            </button>

            <button type="button" title="Bypass (pilih durasi & PIN)"
                    wire:click="$dispatch('buka-kelola-tv', { unitId: '{{ $unit->id }}' })"
                    @class(['btn btn-ikon h-8 w-8', 'text-st-main' => $tv->sedangBypass(), 'text-muted' => ! $tv->sedangBypass()])>
                <x-ikon name="gembok-buka" size="16" />
            </button>

            @if ($bisaRemote)
                <x-confirm-button action="perintahTv" :params="[$unit->id, 'restart_tv']"
                                  title="Restart {{ $unit->nama }}?"
                                  :text="$bisaReboot ? 'TV akan dinyalakan ulang (±1 menit).' : 'TV ini tidak mengizinkan restart penuh; aplikasi TV Agent yang akan dimulai ulang.'"
                                  confirm-text="Restart"
                                  class="btn-ikon h-8 w-8 text-muted" title="Restart TV">
                    <x-ikon name="restart" size="16" />
                </x-confirm-button>
            @endif
        </div>
    @endif

    {{-- Aksi --}}
    <div class="px-3 py-2.5 border-t border-line">
        {{ $slot }}
    </div>
</div>
