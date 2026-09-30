@php
    $status = $this->getStatus();
    $warna = [
        'ok' => ['#22c55e', 'Normal'],
        'peringatan' => ['#f59e0b', 'Perlu dicek'],
        'mati' => ['#ef4444', 'Bermasalah'],
        'info' => ['#38bdf8', 'Info'],
    ];
    $ringkas = collect($status)->countBy('status');
@endphp
<x-filament-widgets::widget>
    <x-filament::section wire:poll.60s>
        <x-slot name="heading">Status sistem</x-slot>
        <x-slot name="description">
            {{ $ringkas['ok'] ?? 0 }} normal
            @if ($ringkas['peringatan'] ?? 0) · {{ $ringkas['peringatan'] }} perlu dicek @endif
            @if ($ringkas['mati'] ?? 0) · <span style="color:#ef4444">{{ $ringkas['mati'] }} bermasalah</span> @endif
            · diperbarui otomatis tiap menit
        </x-slot>
        <x-slot name="afterHeader">
            <div style="display:flex; flex-wrap:wrap; gap:8px;">
                <x-filament::button size="sm" color="gray" icon="heroicon-o-arrow-path" wire:click="periksaUlang" wire:loading.attr="disabled">
                    Periksa ulang
                </x-filament::button>
                <x-filament::button size="sm" color="gray" icon="heroicon-o-signal" wire:click="tesCloud" wire:loading.attr="disabled">
                    Tes koneksi cloud
                </x-filament::button>
                <x-filament::button size="sm" icon="heroicon-o-cloud-arrow-up" wire:click="sinkron" wire:loading.attr="disabled">
                    Sync sekarang
                </x-filament::button>
                @if ($this->bisaBackup())
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-circle-stack" wire:click="backupSekarang" wire:loading.attr="disabled">
                        Backup sekarang
                    </x-filament::button>
                @endif
            </div>
        </x-slot>

        <div style="display:grid; gap:12px; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));">
            @foreach ($status as $kunci => $s)
                @php [$hex, $label] = $warna[$s['status']] ?? $warna['info']; @endphp
                <div wire:key="status-{{ $kunci }}"
                     style="border: 1px solid rgba(127,127,127,.25); border-left: 3px solid {{ $hex }}; border-radius: 10px; padding: 12px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
                        <span style="font-size:11px; font-weight:600; letter-spacing:.06em; text-transform:uppercase; opacity:.65;">{{ $s['judul'] }}</span>
                        <span style="display:inline-flex; align-items:center; gap:4px; font-size:11px; color: {{ $hex }}">
                            <span style="width:7px;height:7px;border-radius:9999px;background:{{ $hex }};display:inline-block"></span>
                            {{ $label }}
                        </span>
                    </div>
                    <div style="margin-top:4px; font-size:16px; font-weight:600;">{{ $s['nilai'] }}</div>
                    <div style="margin-top:2px; font-size:12px; opacity:.65; overflow-wrap:anywhere;">{{ $s['detail'] }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
