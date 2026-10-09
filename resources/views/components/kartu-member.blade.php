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
            <div class="label">{{ __('Sisa saldo') }}</div>
            <x-rupiah :nilai="$member->saldo" class="font-semibold text-accent" />
        </div>
        <div class="rounded-md border border-line bg-surface px-3 py-2">
            <div class="label">{{ __('Poin') }}{{ $target > 0 ? ' · '.__('Stamp') : '' }}</div>
            <span class="font-semibold num" style="color: var(--status-main)">{{ __(':n Poin', ['n' => number_format($member->poin, 0, ',', '.')]) }}</span>
            @if ($target > 0)
                <span class="text-xs text-muted num">· {{ $member->stamp }}/{{ $target }}</span>
            @endif
        </div>
    </div>

    @if ($diskon > 0)
        <div class="rounded-md px-3 py-2 text-xs" style="background: color-mix(in srgb, var(--accent) 12%, transparent); color: var(--accent)">
            {{ __('Benefit member :tier: diskon otomatis :persen% biaya sewa', ['tier' => $member->tier, 'persen' => $diskon]) }}
        </div>
    @endif
    @if ($berikutnya)
        <div class="text-xs text-muted">
            {!! __('Belanja :nominal lagi untuk naik ke :tier (:persen%).', [
                'nominal' => '<span class="num text-fg">'.e(\App\Support\Rupiah::teks((int) $berikutnya['kurang'])).'</span>',
                'tier' => e($berikutnya['nama']), 'persen' => (int) $berikutnya['diskon_persen'],
            ]) !!}
        </div>
    @endif
</div>
