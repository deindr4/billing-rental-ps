@php
    $warna = [
        'kosong' => ['var(--status-kosong)', 'Siap dimainkan'],
        'main' => ['var(--status-main)', 'Terisi'],
        'hampir' => ['var(--status-hampir-habis)', 'Hampir selesai'],
        'jeda' => ['var(--status-pause)', 'Jeda'],
        'bayar' => ['var(--status-pause)', 'Segera kosong'],
        'servis' => ['var(--status-servis)', 'Maintenance'],
    ];
    $rp = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
    $t = $d['turnamen'];
    // Nilai awal dari server (billboard.js memperbarui tiap detik); tanpa ini sempat tampil --:-- setiap Livewire memperbarui halaman
    $durasi = function ($ms) {
        $s = max(0, intdiv((int) $ms, 1000));

        return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    };
    $jam = \Illuminate\Support\Carbon::createFromTimestampMs($d['server_ms'])->setTimezone(config('app.timezone'))->format('H:i');
@endphp
<div wire:poll.10s style="--accent: {{ $d['aksen'] }};" x-data x-init="window.aturWaktuServer({{ $d['server_ms'] }})">
    {{-- ================= Kepala ================= --}}
    <header class="sticky top-0 z-10 border-b border-line bg-bg/95 backdrop-blur">
        <div class="max-w-6xl mx-auto px-4 py-3 flex items-center gap-3">
            @if ($d['logo'])
                <img src="{{ $d['logo'] }}" alt="" class="h-10 w-10 rounded-lg object-contain shrink-0">
            @endif
            <div class="min-w-0">
                <div class="font-bold leading-tight truncate sm:text-lg">{{ $d['tenant'] }}</div>
                <div class="label truncate">{{ $d['cabang'] }}</div>
            </div>
            <div class="ml-auto text-right shrink-0">
                <div class="text-xl font-semibold num" data-jam>{{ $jam }}</div>
                @if ($d['buka'])
                    <div class="label flex items-center justify-end gap-1" style="color: var(--status-kosong)"><span class="dot bb-denyut"></span> Buka</div>
                @else
                    <div class="label flex items-center justify-end gap-1" style="color: var(--status-offline)"><span class="dot"></span> Tutup</div>
                @endif
            </div>
        </div>
    </header>

    {{-- Teks berjalan --}}
    @if ($d['pengumuman'])
        <div class="border-b border-line bg-surface overflow-hidden py-2 text-sm sm:text-base">
            <div class="bb-marquee" style="--durasi: {{ max(15, (int) (mb_strlen($d['pengumuman']) / 5)) }}s">{{ $d['pengumuman'] }}</div>
        </div>
    @endif

    <main class="max-w-6xl mx-auto px-4 py-5 space-y-6">
        {{-- ================= Buka / tutup & jam operasional ================= --}}
        @unless ($d['buka'])
            <section class="kartu p-5 text-center space-y-1" style="border-color: var(--status-offline)">
                <div class="text-2xl font-bold" style="color: var(--status-offline)">Rental sedang tutup</div>
                <div class="text-sm text-muted">
                    Buka {{ $d['jam']['keterangan'] ? mb_strtolower($d['jam']['keterangan']).' ' : '' }}jam
                    <span class="num text-fg font-semibold">{{ $d['jam']['buka'] }}–{{ $d['jam']['tutup'] }}</span>.
                    @if ($d['booking_url']) Tetap bisa booking untuk nanti. @endif
                </div>
            </section>
        @endunless

        <section class="flex items-center justify-between gap-3 text-sm">
            <span class="text-muted">Jam operasional{{ $d['jam']['keterangan'] ? ' · '.$d['jam']['keterangan'] : '' }}</span>
            <span class="num font-semibold">{{ $d['jam']['buka'] }}–{{ $d['jam']['tutup'] }}</span>
        </section>

        {{-- ================= Ringkasan ================= --}}
        @if ($d['buka'])
        <section class="grid grid-cols-3 gap-2 sm:gap-3">
            <div class="kartu p-3 sm:p-4">
                <div class="label">Unit kosong</div>
                <div class="text-2xl sm:text-3xl font-semibold num" style="color: var(--status-kosong)">{{ $d['kosong'] }}</div>
            </div>
            <div class="kartu p-3 sm:p-4">
                <div class="label">Terisi</div>
                <div class="text-2xl sm:text-3xl font-semibold num" style="color: var(--status-main)">{{ $d['terisi'] }}<span class="text-base text-muted">/{{ $d['total'] }}</span></div>
            </div>
            <div class="kartu p-3 sm:p-4">
                <div class="label">Booking hari ini</div>
                <div class="text-2xl sm:text-3xl font-semibold num">{{ count($d['booking']) }}</div>
            </div>
        </section>
        @endif

        @if ($d['booking_url'])
            <a href="{{ $d['booking_url'] }}" class="btn btn-primary w-full h-12 text-base">Booking online sekarang</a>
        @endif

        {{-- ================= Stasiun ================= --}}
        <section>
            <div class="label mb-2">Status stasiun · diperbarui otomatis</div>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($d['matriks'] as $u)
                    @php
                        [$hex, $label] = $warna[$u['status']];
                        if (! $d['buka'] && $u['status'] === 'kosong') {
                            [$hex, $label] = ['var(--text-muted)', 'Tutup'];
                        }
                    @endphp
                    <div wire:key="u-{{ $u['id'] }}" @class(['kartu p-4', 'opacity-60' => ! $d['buka']]) style="border-top: 3px solid {{ $hex }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-lg font-bold truncate">{{ $u['nama'] }}</div>
                                <div class="label">{{ collect([$u['tipe'], $u['kategori']])->filter()->implode(' · ') }}</div>
                            </div>
                            <span class="chip shrink-0 {{ $u['status'] === 'hampir' ? 'bb-denyut' : '' }}" style="color: {{ $hex }}; border-color: {{ $hex }}">{{ $label }}</span>
                        </div>

                        <div class="mt-3 flex items-end justify-between gap-3">
                            @if ($u['berakhir_ms'] && in_array($u['status'], ['main', 'hampir'], true))
                                <div>
                                    <div class="label">Sisa waktu</div>
                                    <div class="text-2xl font-semibold num" style="color: {{ $hex }}" data-berakhir="{{ $u['berakhir_ms'] }}">{{ $durasi($u['berakhir_ms'] - $d['server_ms']) }}</div>
                                </div>
                            @elseif ($u['mulai_ms'] && in_array($u['status'], ['main', 'hampir'], true))
                                <div>
                                    <div class="label">Sudah main</div>
                                    <div class="text-2xl font-semibold num" style="color: {{ $hex }}" data-mulai="{{ $u['mulai_ms'] }}">{{ $durasi($d['server_ms'] - $u['mulai_ms']) }}</div>
                                </div>
                            @else
                                <div class="text-sm text-muted">{{ $u['status'] === 'kosong' && $d['buka'] ? 'Langsung datang & main' : $label }}</div>
                            @endif

                            @if ($u['tarif'])
                                <div class="text-right shrink-0">
                                    <div class="label">Tarif</div>
                                    <div class="font-semibold num">{{ $rp($u['tarif']) }}<span class="text-xs text-muted">/jam</span></div>
                                </div>
                            @endif
                        </div>

                        @if ($u['booking'])
                            <div class="mt-2 text-xs" style="color: var(--status-hampir-habis)">Sudah dibooking jam {{ $u['booking'] }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ================= Booking hari ini ================= --}}
        @if ($d['booking'])
            <section class="kartu p-4">
                <div class="label mb-2">Booking hari ini</div>
                <div class="divide-y divide-line">
                    @foreach ($d['booking'] as $b)
                        <div class="flex justify-between gap-3 py-2 text-sm">
                            <span><span class="num font-semibold" style="color: var(--status-hampir-habis)">{{ $b['jam'] }}</span> · {{ $b['nama'] }}</span>
                            <span class="text-muted">{{ $b['unit'] }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ================= Turnamen ================= --}}
        @if ($t)
            <section class="kartu p-4 space-y-3">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="label" style="color: var(--accent)">Turnamen · {{ $t['status'] === 'berjalan' ? 'sedang berlangsung' : 'pendaftaran dibuka' }}</div>
                        <div class="text-lg font-bold">{{ $t['nama'] }}</div>
                        <div class="text-sm text-muted">{{ $t['game'] }} · {{ $t['mulai'] }}</div>
                    </div>
                    <div class="text-right text-sm">
                        <div class="num font-semibold">{{ $t['peserta'] }}/{{ $t['kuota'] }} peserta</div>
                        @if ($t['biaya'] > 0) <div class="text-muted">Daftar {{ $rp($t['biaya']) }}{{ $t['bonus'] ? ' + '.$t['bonus'] : '' }}</div> @endif
                        <div class="text-muted">{{ $t['format'] }}</div>
                    </div>
                </div>
                @if ($t['total_hadiah'] > 0) <p class="text-sm"><span class="label">Total hadiah</span> <b style="color: var(--accent)">{{ $rp($t['total_hadiah']) }}</b></p> @endif
                @if ($t['hadiah']) <p class="text-sm"><span class="label">Hadiah</span> {{ $t['hadiah'] }}</p> @endif

                @foreach ($t['bagian'] as $bg)
                    <div class="label mt-1">{{ $bg['judul'] }}</div>
                    @if ($bg['klasemen'])
                        <div class="rounded-md border border-line bg-bg text-sm divide-y divide-line">
                            @foreach ($bg['klasemen'] as $i => $r)
                                <div class="flex items-center gap-2 px-3 py-1.5 {{ $bg['lolos'] && $i < $bg['lolos'] ? 'font-semibold' : '' }}" style="{{ $bg['lolos'] && $i < $bg['lolos'] ? 'color: var(--accent)' : '' }}">
                                    <span class="num w-5 text-muted">{{ $i + 1 }}</span>
                                    <span class="flex-1 truncate">{{ $r['nama'] }}</span>
                                    <span class="num text-muted text-xs">{{ $r['main'] }} main · {{ $r['sg'] > 0 ? '+' : '' }}{{ $r['sg'] }}</span>
                                    <span class="num w-8 text-right font-bold">{{ $r['poin'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @if ($bg['kolom'])
                    <div class="flex gap-3 overflow-x-auto pb-1 -mx-1 px-1">
                        @foreach ($bg['kolom'] as $b)
                            <div class="min-w-40 flex-1 flex flex-col">
                                <div class="label text-center mb-2">{{ $b['nama'] }}</div>
                                <div class="flex-1 flex flex-col justify-around gap-2">
                                    @foreach ($b['laga'] as $l)
                                        <div class="rounded-md border border-line bg-bg p-2 text-sm {{ $l['status'] === 'main' ? 'bb-denyut' : '' }}" style="{{ $l['status'] === 'main' ? 'border-color: var(--accent)' : '' }}">
                                            @foreach ([['a', 'skor_a'], ['b', 'skor_b']] as [$s, $sk])
                                                @php $menang = $l['pemenang'] && $l['pemenang'] === $l[$s]; @endphp
                                                <div class="flex justify-between gap-2 {{ $menang ? 'font-bold' : ($l['pemenang'] ? 'text-muted' : '') }}" style="{{ $menang ? 'color: var(--accent)' : '' }}">
                                                    <span class="truncate">{{ $l[$s] ?? 'TBD' }}</span>
                                                    <span class="num">{{ $l[$sk] }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @endif
                @endforeach
            </section>
        @endif

        {{-- ================= Iklan (bergilir tiap 6 detik) ================= --}}
        @if ($d['iklan'])
            <section wire:key="iklan-{{ collect($d['iklan'])->pluck('id')->implode('-') }}"
                     x-data="{ i: 0, n: {{ count($d['iklan']) }}, init() { if (this.n > 1) setInterval(() => this.i = (this.i + 1) % this.n, 6000) } }">
                <div class="label mb-2">Iklan</div>
                <div class="relative">
                    @foreach ($d['iklan'] as $k => $ik)
                        <div x-show="i === {{ $k }}" @if ($k > 0) x-cloak @endif x-transition.opacity.duration.500ms>
                            @if ($ik['tautan'])
                                <a href="{{ $ik['tautan'] }}" target="_blank" rel="noopener sponsored">
                            @endif
                            <img src="{{ $ik['gambar'] }}" alt="{{ $ik['judul'] }}" loading="lazy"
                                 class="w-full rounded-xl border border-line object-cover" style="aspect-ratio: {{ $ik['rasio'] }}; max-height: 70vh;">
                            @if ($ik['tautan'])
                                </a>
                            @endif
                        </div>
                    @endforeach
                </div>
                @if (count($d['iklan']) > 1)
                    <div class="flex justify-center gap-1.5 mt-2">
                        @foreach ($d['iklan'] as $k => $ik)
                            <button type="button" @click="i = {{ $k }}" class="h-2 rounded-full transition-all" :class="i === {{ $k }} ? 'w-5 bg-accent' : 'w-2 bg-line'" aria-label="Iklan {{ $k + 1 }}"></button>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        <footer class="text-center text-xs text-muted pb-4 space-y-1">
            @if ($d['alamat']) <div>{{ $d['alamat'] }}</div> @endif
            @if ($d['telepon'])
                <a href="https://wa.me/{{ preg_replace('/^0/', '62', preg_replace('/\D/', '', $d['telepon'])) }}" class="text-accent">WhatsApp {{ $d['telepon'] }}</a>
            @endif
        </footer>
    </main>
</div>
