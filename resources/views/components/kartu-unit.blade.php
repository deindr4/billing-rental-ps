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

    // Indikator TV Agent / agen PC: [teks, warna, keterangan]
    $alat = ($tv?->isPc() ?? $unit->isPc()) ? 'PC' : 'TV';
    $indikatorTv = match (true) {
        $tv === null && $unit->pakaiTvAgent() => ["{$alat} belum dipasang", 'var(--text-muted)', "Pasangkan {$alat} di Admin → Perangkat TV & PC"],
        $tv === null => null,
        ! $tv->isOnline() => ["{$alat} offline", 'var(--status-offline)', $tv->terakhir_online ? 'Terakhir terlihat '.$tv->terakhir_online->diffForHumans() : 'Belum pernah tersambung'],
        $tv->sedangBypass() => ["{$alat} bypass s/d ".$tv->bypass_sampai->format('H:i'), 'var(--status-hampir-habis)', "{$alat} terbuka sementara tanpa sesi"],
        default => ["{$alat} online", 'var(--status-kosong)', $alat === 'PC' ? 'Agen kiosk PC tersambung' : 'TV Agent tersambung'],
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
                                title="Pilih / pindah HDMI">
                            {{ $tv->input_hdmi ? $tv->labelHdmi($tv->input_hdmi) : 'Pilih HDMI' }}
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
                @if ($aksesori && $aksesori->isNotEmpty())
                    <div class="flex justify-between gap-2">
                        <dt class="text-muted flex items-center gap-1"><x-ikon name="aksesori" size="13" class="text-ik-ungu" /> Aksesori</dt>
                        <dd class="truncate">{{ $aksesori->map(fn ($s) => ($s->aksesori?->nama ?? '').($s->qty > 1 ? ' ×'.$s->qty : ''))->implode(', ') }}</dd>
                    </div>
                @endif
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

    {{-- Remote PC (agen kiosk Windows): mati → Nyalakan (Wake-on-LAN); menyala → kunci, tutup game, Task Manager, daya --}}
    @if ($tv && $tv->isPc() && ! $tv->isOnline())
        <div class="px-3 py-2.5 border-t border-line flex items-center justify-center gap-2">
            <span class="text-xs text-muted">PC mati / offline</span>
            @if ($bisaRemote)
                <button type="button" title="Nyalakan PC (Wake-on-LAN)" wire:click="nyalakanPc('{{ $unit->id }}')"
                        class="btn btn-remote text-st-kosong">
                    <x-ikon name="daya" size="16" />
                </button>
            @endif
        </div>
    @elseif ($tv && $tv->isPc())
        <div class="px-3 py-2.5 border-t border-line flex items-center justify-center gap-1.5 min-[380px]:gap-2">
            <span title="Tutup game yang hang">
                <x-confirm-button action="perintahTv" :params="[$unit->id, 'tutup_game']"
                                  title="Tutup game di {{ $unit->nama }}?"
                                  text="Aplikasi yang sedang di depan dipaksa tutup, pemain kembali ke kiosk. Waktu sewa tetap berjalan."
                                  confirm-text="Tutup game"
                                  class="btn-remote text-danger">
                    <x-ikon name="tutup-game" size="16" />
                </x-confirm-button>
            </span>
            @if ($bisaRemote)
                <span title="Izinkan Task Manager sementara">
                    <x-confirm-button action="izinTaskManager" :params="[$unit->id]"
                                      title="Buka Task Manager di {{ $unit->nama }}?"
                                      text="Task Manager boleh dibuka sementara (lihat Admin → Pengaturan Operasional → Rental PC), lalu diblok lagi otomatis."
                                      confirm-text="Izinkan"
                                      class="btn-remote text-ik-kuning">
                        <x-ikon name="aktivitas" size="16" />
                    </x-confirm-button>
                </span>
            @endif

            <button type="button" title="Pemberitahuan ke layar PC"
                    wire:click="$dispatch('buka-pemberitahuan', { unitId: '{{ $unit->id }}' })"
                    class="btn btn-remote text-ik-pink ml-1 min-[380px]:ml-2">
                <x-ikon name="pengumuman" size="16" />
            </button>
            <button type="button" title="Bypass (pilih durasi & PIN)"
                    wire:click="$dispatch('buka-kelola-tv', { unitId: '{{ $unit->id }}' })"
                    @class(['btn btn-remote', 'text-st-main bg-st-main/15' => $tv->sedangBypass(), 'text-ik-ungu' => ! $tv->sedangBypass()])>
                <x-ikon name="gembok-buka" size="16" />
            </button>

            @if ($bisaRemote)
                <span title="Kunci PC" class="ml-1 min-[380px]:ml-2">
                    <x-confirm-button action="perintahTv" :params="[$unit->id, 'kunci']"
                                      title="Kunci {{ $unit->nama }}?" text="Layar kiosk PC dikunci lagi." confirm-text="Kunci"
                                      class="btn-remote text-ik-biru">
                        <x-ikon name="gembok" size="16" />
                    </x-confirm-button>
                </span>
                <span title="Log off akun pemain">
                    <x-confirm-button action="perintahTv" :params="[$unit->id, 'logoff_pc']"
                                      title="Log off akun pemain di {{ $unit->nama }}?" text="Semua aplikasi pemain ditutup, Windows kembali ke layar login lalu kiosk." confirm-text="Log off"
                                      class="btn-remote text-ik-ungu">
                        <x-ikon name="keluar" size="16" />
                    </x-confirm-button>
                </span>
                <span title="Restart PC">
                    <x-confirm-button action="perintahTv" :params="[$unit->id, 'restart_pc']"
                                      title="Restart {{ $unit->nama }}?" text="PC dinyalakan ulang (±1–2 menit)." confirm-text="Restart"
                                      class="btn-remote text-ik-teal">
                        <x-ikon name="restart" size="16" />
                    </x-confirm-button>
                </span>
                <span title="Matikan PC">
                    <x-confirm-button action="perintahTv" :params="[$unit->id, 'matikan_pc']"
                                      title="Matikan {{ $unit->nama }}?" text="PC dimatikan. Nyalakan lagi dari tombol daya di kartu ini (Wake-on-LAN) atau manual." confirm-text="Matikan"
                                      class="btn-remote text-ik-oranye">
                        <x-ikon name="daya" size="16" />
                    </x-confirm-button>
                </span>
            @endif
        </div>

    {{-- Remote TV. TV standby berhenti melapor (tampak offline): tombol Bangunkan tetap ada --}}
    @elseif ($tv && ! $tv->isOnline())
        <div class="px-3 py-2.5 border-t border-line flex items-center justify-center gap-2">
            <span class="text-xs text-muted">TV offline / standby</span>
            @if ($bisaRemote)
                <button type="button" title="Bangunkan TV"
                        wire:click="perintahTv('{{ $unit->id }}', 'layar_nyala')"
                        class="btn btn-remote text-st-kosong">
                    <x-ikon name="bangun" size="16" />
                </button>
            @endif
        </div>
    @elseif ($tv)
        @php
            $layarMati = $tv->layar_hidup === false;
            $bisaReboot = (bool) ($tv->diagnostik['device_owner'] ?? false);
        @endphp
        {{-- Dikelompokkan: daya | volume | pemberitahuan, bypass & restart (jarak antar kelompok lebih lebar) --}}
        <div class="px-3 py-2.5 border-t border-line flex items-center justify-center gap-1.5 min-[380px]:gap-2">
            @if ($bisaRemote)
                {{-- Dua tombol terpisah (bukan satu tombol yang berganti) supaya jelas: matahari = bangunkan, daya = matikan --}}
                <button type="button" title="Bangunkan TV"
                        wire:click="perintahTv('{{ $unit->id }}', 'layar_nyala')"
                        @class(['btn btn-remote', 'text-st-kosong bg-st-kosong/15' => $layarMati, 'text-st-kosong' => ! $layarMati])>
                    <x-ikon name="bangun" size="16" />
                </button>
                <x-confirm-button action="perintahTv" :params="[$unit->id, 'layar_mati']"
                                  title="Matikan layar {{ $unit->nama }}?"
                                  text="TV masuk mode standby. Bangunkan lagi dari tombol matahari atau remote TV."
                                  confirm-text="Matikan"
                                  class="btn-remote text-ik-oranye" title="Matikan layar TV">
                    <x-ikon name="daya" size="16" />
                </x-confirm-button>
            @endif

            <button type="button" title="Volume turun" wire:click="perintahTv('{{ $unit->id }}', 'volume_turun')"
                    @class(['btn btn-remote text-ik-biru', 'ml-1 min-[380px]:ml-2' => $bisaRemote])>
                <x-ikon name="vol-turun" size="16" />
            </button>
            <span class="num text-xs w-8 text-center {{ $tv->senyap ? 'text-danger' : 'text-muted' }}" title="Volume TV">
                {{ $tv->senyap ? 'MUTE' : ($tv->volume !== null ? $tv->volume.'%' : '–') }}
            </span>
            <button type="button" title="Volume naik" wire:click="perintahTv('{{ $unit->id }}', 'volume_naik')"
                    class="btn btn-remote text-ik-biru">
                <x-ikon name="vol-naik" size="16" />
            </button>
            <button type="button" title="Senyap / bunyikan" wire:click="perintahTv('{{ $unit->id }}', 'volume_senyap')"
                    @class(['btn btn-remote', 'text-danger bg-danger/15' => $tv->senyap, 'text-ik-kuning' => ! $tv->senyap])>
                <x-ikon name="senyap" size="16" />
            </button>

            <button type="button" title="Pemberitahuan ke layar TV"
                    wire:click="$dispatch('buka-pemberitahuan', { unitId: '{{ $unit->id }}' })"
                    class="btn btn-remote text-ik-pink ml-1 min-[380px]:ml-2">
                <x-ikon name="pengumuman" size="16" />
            </button>

            <button type="button" title="Bypass (pilih durasi & PIN)"
                    wire:click="$dispatch('buka-kelola-tv', { unitId: '{{ $unit->id }}' })"
                    @class(['btn btn-remote', 'text-st-main bg-st-main/15' => $tv->sedangBypass(), 'text-ik-ungu' => ! $tv->sedangBypass()])>
                <x-ikon name="gembok-buka" size="16" />
            </button>

            @if ($bisaRemote)
                <x-confirm-button action="perintahTv" :params="[$unit->id, 'restart_tv']"
                                  title="Restart {{ $unit->nama }}?"
                                  :text="$bisaReboot ? 'TV akan dinyalakan ulang (±1 menit).' : 'TV ini tidak mengizinkan restart penuh; aplikasi TV Agent yang akan dimulai ulang.'"
                                  confirm-text="Restart"
                                  class="btn-remote text-ik-teal" title="Restart TV">
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
