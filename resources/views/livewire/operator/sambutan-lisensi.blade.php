<div>
    @if ($buka)
        <x-sheet wire:model="buka" :judul="__('Selamat datang di Delta Billing HuB')" max-width="sm:max-w-2xl">
            <div class="space-y-4 text-sm">
                <div>
                    <div class="label">{{ __('Perangkat lunak gratis & terbuka') }}</div>
                    <div class="text-lg font-semibold tracking-tight">{{ __('Lisensi MIT') }}</div>
                    <p class="text-muted">Delta Billing HuB{{ $versi ? ' · v'.$versi : '' }} · Copyright © deindr4</p>
                </div>

                @include('partials.ringkasan-lisensi')

                <p class="text-muted">{{ __('Butuh bantuan atau ingin tahu fitur & rilis terbaru? Hubungi pengembang atau gabung grup:') }}</p>

                @include('partials.kontak-pengembang', ['pilih' => ['telegram', 'whatsapp']])
            </div>

            <x-slot:footer>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <a href="{{ route('lisensi') }}" wire:navigate class="text-xs text-muted underline" @click="buka = false">
                        {{ __('Teks lisensi lengkap: menu Lisensi MIT') }}
                    </a>
                    <button type="button" class="btn btn-primary h-10 px-6" @click="buka = false">{{ __('Mengerti') }}</button>
                </div>
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
