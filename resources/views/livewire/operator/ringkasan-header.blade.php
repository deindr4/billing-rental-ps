<div wire:poll.30s class="flex items-stretch">
    <div class="pr-5">
        <div class="label">{{ __('Terisi') }}</div>
        <div class="num font-semibold leading-tight">
            <span class="text-accent">{{ $terisi }}</span><span class="text-muted">/{{ $total }}</span>
        </div>
    </div>
    <div class="px-5 border-l border-line">
        <div class="label">{{ __('Okupansi') }}</div>
        <div class="num font-semibold leading-tight">{{ $okupansi }}%</div>
    </div>
    <div class="px-5 border-l border-line">
        <div class="label">{{ __('Omzet shift') }}</div>
        <x-rupiah :nilai="$omzet" rahasia class="font-semibold leading-tight text-accent" />
    </div>
    <div class="px-5 border-l border-line">
        <div class="label">{{ __('Kas laci') }}</div>
        <x-rupiah :nilai="$kas" rahasia class="font-semibold leading-tight" />
    </div>
</div>
