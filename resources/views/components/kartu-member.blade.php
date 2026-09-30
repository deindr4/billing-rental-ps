@props(['member', 'diskon' => 0, 'berikutnya' => null])

{{-- Kartu member (Stitch 08): inisial, nama + tier, kode, saldo, poin, stamp, manfaat tier --}}
@php
    $warnaTier = match (mb_strtolower($member->tier)) {
        'silver' => 'var(--status-pause)',
        'gold' => 'var(--status-hampir-habis)',
        'platinum' => 'var(--status-main)',
        default => 'var(--text-muted)',
    };
    $target = app(\App\Services\Member\PengaturanMember::class)->targetStamp();
@endphp
<div {{ $attributes->merge(['class' => 'rounded-md border border-line bg-bg p-3 space-y-3']) }}>
    <div class="flex items-start gap-3">
        <span class="h-11 w-11 shrink-0 rounded-md grid place-items-center font-semibold text-lg"
              style="background: color-mix(in srgb, {{ $warnaTier }} 20%, transparent); color: {{ $warnaTier }}">{{ $member->inisial() }}</span>
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2 min-w-0">
                <span class="font-semibold truncate">{{ $member->nama }}</span>
                <span class="chip shrink-0" style="color: {{ $warnaTier }}; border-color: {{ $warnaTier }}">{{ $member->tier }}</span>
            </div>
            <div class="text-xs text-muted num">ID: {{ $member->kode }} · {{ $member->telepon }}</div>
        </div>
        {{ $slot }}
    </div>

    <div class="grid grid-cols-2 gap-2">
        <div class="rounded-md border border-line bg-surface px-3 py-2">
            <div class="label">Sisa saldo</div>
            <x-rupiah :nilai="$member->saldo" class="font-semibold text-accent" />
        </div>
        <div class="rounded-md border border-line bg-surface px-3 py-2">
            <div class="label">Poin{{ $target > 0 ? ' · Stamp' : '' }}</div>
            <span class="font-semibold num" style="color: var(--status-main)">{{ number_format($member->poin, 0, ',', '.') }} Poin</span>
            @if ($target > 0)
                <span class="text-xs text-muted num">· {{ $member->stamp }}/{{ $target }}</span>
            @endif
        </div>
    </div>

    @if ($diskon > 0)
        <div class="rounded-md px-3 py-2 text-xs" style="background: color-mix(in srgb, var(--accent) 12%, transparent); color: var(--accent)">
            Benefit member {{ $member->tier }}: diskon otomatis {{ $diskon }}% biaya sewa
        </div>
    @endif
    @if ($berikutnya)
        <div class="text-xs text-muted">
            Belanja <x-rupiah :nilai="$berikutnya['kurang']" class="text-fg" /> lagi untuk naik ke {{ $berikutnya['nama'] }} ({{ $berikutnya['diskon_persen'] }}%).
        </div>
    @endif
</div>
