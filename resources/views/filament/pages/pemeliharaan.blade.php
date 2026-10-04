<x-filament-panels::page>
    @php
        $baris = 'display:flex; justify-content:space-between; gap:12px; padding:6px 0; border-top:1px solid rgba(127,127,127,.18); font-size:14px;';
        $redup = 'opacity:.65;';
        $pre = 'white-space:pre-wrap; word-break:break-all; font-size:12px; line-height:1.5; padding:12px; border-radius:8px; background:rgba(127,127,127,.1); max-height:360px; overflow:auto; margin:0;';
    @endphp

    <x-filament::section>
        <x-slot name="heading">Kondisi sistem</x-slot>
        <div>
            @foreach ($this->status as $i => [$label, $nilai, $baik])
                <div style="{{ $baris }} {{ $i === 0 ? 'border-top:none' : '' }}">
                    <span style="{{ $redup }}">{{ $label }}</span>
                    <b style="color: {{ $baik === null ? 'inherit' : ($baik ? '#22c55e' : '#f59e0b') }}">{{ $nilai }}</b>
                </div>
            @endforeach
        </div>
        <p style="font-size:12px; margin-top:8px; {{ $redup }}">Kuning = perlu perhatian di server produksi (mis. debug nyala, cache belum dibuat).</p>
    </x-filament::section>

    @php
        $u = $this->update;
        $jenis = \App\Services\UpdateAplikasi::jenisPemasangan();
        $mb = fn ($b) => number_format($b / 1048576, 0, ',', '.').' MB';
    @endphp
    <x-filament::section>
        <x-slot name="heading">Update aplikasi</x-slot>
        <x-slot name="description">Versi baru diumumkan di GitHub. Diperiksa otomatis tiap 6 jam.</x-slot>
        <x-slot name="afterHeader">
            <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-path" wire:click="cekUpdate" wire:loading.attr="disabled">Cek sekarang</x-filament::button>
        </x-slot>

        <div>
            <div style="{{ $baris }} border-top:none"><span style="{{ $redup }}">Versi terpasang</span><b>{{ \App\Services\UpdateAplikasi::versiSekarang() }}</b></div>
            @if ($u === null)
                <div style="{{ $baris }}"><span style="{{ $redup }}">Versi terbaru</span><span>Cek update dimatikan (UPDATE_REPO kosong)</span></div>
            @elseif (isset($u['error']))
                <div style="{{ $baris }}"><span style="{{ $redup }}">Versi terbaru</span><span style="color:#f59e0b">{{ $u['error'] }}</span></div>
            @else
                <div style="{{ $baris }}"><span style="{{ $redup }}">Versi terbaru</span>
                    <b style="color: {{ $u['baru'] ? '#f59e0b' : '#22c55e' }}">
                        {{ $u['versi'] }}{{ $u['baru'] ? ' — tersedia' : ' — sudah terbaru' }}
                        @if ($u['tanggal']) <span style="font-weight:400; {{ $redup }}">({{ \Illuminate\Support\Carbon::parse($u['tanggal'])->translatedFormat('d M Y') }})</span> @endif
                    </b></div>
            @endif
        </div>

        @if (is_array($u) && ! isset($u['error']) && $u['baru'])
            @if (trim($u['catatan']) !== '')
                <div style="margin-top:12px; font-size:13px; line-height:1.6; padding:12px; border-radius:8px; background:rgba(127,127,127,.08); max-height:320px; overflow:auto" class="prose dark:prose-invert max-w-none">
                    {!! \Illuminate\Support\Str::markdown($u['catatan'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                </div>
            @endif

            <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:12px; align-items:center">
                @if ($u['unduh'])
                    <x-filament::button tag="a" :href="$u['unduh']['url']" icon="heroicon-o-arrow-down-tray">
                        Unduh {{ $u['unduh']['nama'] }} ({{ $mb($u['unduh']['ukuran']) }})
                    </x-filament::button>
                @endif
                @if ($u['halaman'])
                    <x-filament::button tag="a" :href="$u['halaman']" target="_blank" color="gray" icon="heroicon-o-arrow-top-right-on-square">Halaman rilis</x-filament::button>
                @endif
            </div>

            <div style="font-size:13px; margin-top:10px; {{ $redup }}">
                @if ($jenis === 'windows')
                    Jalankan file .exe yang diunduh di PC ini (klik dua kali, izinkan Administrator). Installer otomatis
                    backup database, memperbarui aplikasi & layanan, lalu menyala lagi — data & pengaturan tetap. Aplikasi mati ± 2–5 menit.
                @elseif ($jenis === 'cloud')
                    Unggah file .tar.gz ke server lalu jalankan langkah "Update versi baru" di panduan CloudPanel
                    (down → backup → ekstrak → migrate → optimize → up).
                @else
                    Unduh file yang sesuai dari halaman rilis: .exe untuk PC Windows, .tar.gz untuk server cloud, .apk untuk TV.
                @endif
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Tindakan</x-slot>
        <x-slot name="description">Setelah update aplikasi: Backup → Update database → Pasang ulang trigger sinkron → Optimalkan → Restart antrean & realtime.</x-slot>

        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:12px;">
            @foreach (\App\Filament\Pages\Pemeliharaan::TINDAKAN as $kunci => [$judul, $ket, $perintah, , $konfirmasi, $warna])
                <div wire:key="t-{{ $kunci }}" style="border:1px solid rgba(127,127,127,.25); border-radius:10px; padding:12px; display:flex; flex-direction:column; gap:8px;">
                    <div style="font-weight:600; font-size:14px;">{{ $judul }}</div>
                    <div style="font-size:12px; flex:1; {{ $redup }}">{{ $ket }}</div>
                    <code style="font-size:11px; {{ $redup }}">php artisan {{ $perintah }}</code>
                    @if ($konfirmasi)
                        <x-filament::button size="sm" :color="$warna" wire:click="jalankan('{{ $kunci }}')" wire:confirm="{{ $konfirmasi }}"
                                            wire:loading.attr="disabled" wire:target="jalankan('{{ $kunci }}')">Jalankan</x-filament::button>
                    @else
                        <x-filament::button size="sm" :color="$warna" wire:click="jalankan('{{ $kunci }}')"
                                            wire:loading.attr="disabled" wire:target="jalankan('{{ $kunci }}')">Jalankan</x-filament::button>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($hasil)
            <div style="margin-top:16px;">
                <div style="font-size:14px; font-weight:600; margin-bottom:6px; color: {{ $hasilGagal ? '#ef4444' : '#22c55e' }}">
                    Hasil: {{ $hasilJudul }} {{ $hasilGagal ? '(gagal)' : '' }}
                </div>
                <pre style="{{ $pre }}">{{ $hasil }}</pre>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Log error (40 baris terakhir)</x-slot>
        @if ($this->log)
            <pre style="{{ $pre }}">{{ $this->log }}</pre>
            <div style="margin-top:8px;">
                <x-filament::button size="sm" color="gray" wire:click="kosongkanLog" wire:confirm="Kosongkan file log error?">Kosongkan log</x-filament::button>
            </div>
        @else
            <p style="font-size:14px; {{ $redup }}">Log kosong — tidak ada error tercatat.</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
