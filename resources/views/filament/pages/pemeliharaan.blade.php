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
