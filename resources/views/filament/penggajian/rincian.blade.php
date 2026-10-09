@php
    /** @var \App\Models\Penggajian $p */
    $p = $getRecord();
    $r = $p->rincian ?? [];
    $h = $r['hadir'] ?? [];
    $t = $r['tarif'] ?? [];
    $rp = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
    $jam = fn ($m) => intdiv((int) $m, 60).' j '.((int) $m % 60).' m';
@endphp

<x-filament::section>
    <x-slot name="heading">{{ $p->karyawan?->nama }} · {{ $p->labelPeriode() }}</x-slot>
    <x-slot name="description">
        {{ \App\Models\Penggajian::STATUS[$p->status] ?? $p->status }} · {{ $p->nomor }}
        @if ($p->dibayar_pada) · dibayar {{ $p->dibayar_pada->format('d/m/Y H:i') }} ({{ \App\Models\Pengeluaran::SUMBER_DANA[$p->sumber_dana] ?? $p->sumber_dana }}) @endif
    </x-slot>

    <table class="w-full text-sm">
        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
            <tr><td class="py-1.5">Gaji pokok{{ ($r['pokok_prorata'] ?? false) ? ' (prorata '.($r['hari_periode'] ?? 0).' hari)' : '' }}</td><td class="py-1.5 text-right">{{ $rp($p->gaji_pokok) }}</td></tr>
            <tr><td class="py-1.5">Upah hadir · {{ $h['hari_hadir'] ?? 0 }} hari × {{ $rp($t['upah_shift'] ?? 0) }}</td><td class="py-1.5 text-right">{{ $rp($p->upah_hadir) }}</td></tr>
            <tr><td class="py-1.5">Upah jam · {{ $jam($h['menit_kerja'] ?? 0) }} × {{ $rp($t['upah_jam'] ?? 0) }}/jam</td><td class="py-1.5 text-right">{{ $rp($p->upah_jam) }}</td></tr>
            <tr><td class="py-1.5">Bonus target omzet · {{ count($r['bonus_shift'] ?? []) }} shift tercapai</td><td class="py-1.5 text-right">{{ $rp($p->bonus) }}</td></tr>
            @if ($p->penyesuaian !== 0)
                <tr><td class="py-1.5">Penyesuaian</td><td class="py-1.5 text-right">{{ $rp($p->penyesuaian) }}</td></tr>
            @endif
            @if ($p->potongan > 0)
                <tr class="text-danger-600"><td class="py-1.5">Potongan selisih kas (disetujui)</td><td class="py-1.5 text-right">− {{ $rp($p->potongan) }}</td></tr>
            @endif
            <tr class="font-semibold text-base"><td class="py-2">Total diterima</td><td class="py-2 text-right">{{ $rp($p->total) }}</td></tr>
        </tbody>
    </table>

    <div class="mt-3 text-xs text-gray-500 space-y-1">
        <div>Kehadiran: {{ $h['hari_hadir'] ?? 0 }} hari · terlambat {{ $h['terlambat_kali'] ?? 0 }}× ({{ $h['terlambat_menit'] ?? 0 }} menit){{ ($h['belum_pulang'] ?? 0) > 0 ? ' · '.$h['belum_pulang'].' absensi belum pulang (koreksi di menu Absensi)' : '' }}</div>
        @if (! empty($r['bonus_shift']))
            <div>Bonus: @foreach ($r['bonus_shift'] as $b){{ $b['tanggal'] }} {{ $b['shift'] }} omzet {{ $rp($b['omzet']) }} → {{ $rp($b['bonus']) }}@if (! $loop->last) · @endif @endforeach</div>
        @endif
    </div>
</x-filament::section>
