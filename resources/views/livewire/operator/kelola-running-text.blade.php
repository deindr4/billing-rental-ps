<div>
    <x-sheet wire:model="buka" :judul="__('Running text di TV')">
        @php
            $warnaHex = \App\Support\RunningTextTv::WARNA[$warna] ?? '#FFFFFF';
            $kelasUkuran = ['kecil' => 'text-sm', 'sedang' => 'text-base', 'besar' => 'text-xl'][$ukuran] ?? 'text-base';
            $contoh = __('Promo begadang 6 jam Rp50.000 mulai 23:00 · Turnamen FC Sabtu ini!');
        @endphp

        @if ($tayang)
            <div class="rounded-md border px-3 py-2 mb-4 text-sm flex items-center gap-2"
                 style="border-color: color-mix(in srgb, var(--ikon-hijau) 45%, var(--border)); color: var(--ikon-hijau);">
                <span class="titik-w" style="--w: var(--ikon-hijau)"></span>
                {{ $sampai ? __('Sedang tayang sampai :jam', ['jam' => $sampai->format('H:i')]) : __('Sedang tayang sampai dimatikan') }}
            </div>
        @endif

        {{-- Pratinjau strip --}}
        <div class="rounded-md border border-line overflow-hidden mb-4 relative h-20"
             style="background: linear-gradient(135deg, #1e3a5f, #0a1420);">
            <div class="absolute inset-x-0 {{ $posisi === 'atas' ? 'top-0' : 'bottom-0' }} py-1.5 overflow-hidden"
                 style="background: rgba(0,0,0,{{ $opasitas / 100 }});">
                <div @class(['whitespace-nowrap pl-3', $kelasUkuran, 'font-bold' => $tebal]) style="color: {{ $warnaHex }};">
                    {{ trim($teks) !== '' ? $teks : $contoh }}
                </div>
            </div>
            <div class="absolute inset-0 grid place-items-center text-xs text-white/40 pointer-events-none">{{ __('tampilan game / layar kunci') }}</div>
        </div>

        <div class="label mb-1.5">{{ __('Teks berjalan') }}</div>
        <textarea wire:model.live.debounce.400ms="teks" rows="2" maxlength="300" class="input h-auto py-2"
                  placeholder="{{ $contoh }}"></textarea>
        @error('teks') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror

        <div class="label mt-3 mb-1.5">{{ __('Tayang selama') }}</div>
        <div class="flex flex-wrap gap-1.5">
            @foreach (\App\Support\RunningTextTv::DURASI as $m => $n)
                <button type="button" wire:click="$set('durasi', {{ $m }})"
                        @class(['btn h-8 px-3 text-xs num', 'btn-primary' => $durasi === $m])>{{ __($n) }}</button>
            @endforeach
        </div>

        <label class="flex items-start gap-2 mt-3 text-sm">
            <input type="checkbox" wire:model.live="sembunyiSaatMain" class="size-4 mt-0.5 accent-[var(--accent)]">
            <span>{{ __('Sembunyikan saat unit sedang dimainkan') }}
                <span class="block text-xs text-muted">{{ __('Hanya tampil di TV yang kosong / terkunci. Matikan centang agar ikut berjalan di atas game.') }}</span>
            </span>
        </label>

        <div class="grid sm:grid-cols-2 gap-x-4 gap-y-3 mt-4">
            <div>
                <div class="label mb-1.5">{{ __('Posisi') }}</div>
                <div class="flex gap-1.5">
                    @foreach (\App\Support\RunningTextTv::POSISI as $k => $n)
                        <button type="button" wire:click="$set('posisi', '{{ $k }}')" @class(['btn h-8 px-3 text-xs', 'btn-primary' => $posisi === $k])>{{ __($n) }}</button>
                    @endforeach
                </div>
            </div>
            <div>
                <div class="label mb-1.5">{{ __('Kecepatan') }}</div>
                <div class="flex gap-1.5">
                    @foreach (\App\Support\RunningTextTv::KECEPATAN as $k => $n)
                        <button type="button" wire:click="$set('kecepatan', '{{ $k }}')" @class(['btn h-8 px-3 text-xs', 'btn-primary' => $kecepatan === $k])>{{ __($n) }}</button>
                    @endforeach
                </div>
            </div>
            <div>
                <div class="label mb-1.5">{{ __('Ukuran & tebal') }}</div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach (\App\Support\RunningTextTv::UKURAN as $k => $n)
                        <button type="button" wire:click="$set('ukuran', '{{ $k }}')" @class(['btn h-8 px-3 text-xs', 'btn-primary' => $ukuran === $k])>{{ __($n) }}</button>
                    @endforeach
                    <button type="button" wire:click="$toggle('tebal')" @class(['btn h-8 px-3 text-xs font-bold', 'btn-primary' => $tebal])>B</button>
                </div>
            </div>
            <div>
                <div class="label mb-1.5">{{ __('Warna teks') }}</div>
                <div class="flex gap-1.5">
                    @foreach (\App\Support\RunningTextTv::WARNA as $k => $hex)
                        <button type="button" wire:click="$set('warna', '{{ $k }}')" title="{{ __(ucfirst($k)) }}"
                                @class(['btn h-8 w-8 px-0', 'ring-2 ring-[var(--accent)]' => $warna === $k])>
                            <span class="size-4 rounded-full" style="background: {{ $hex }}"></span>
                        </button>
                    @endforeach
                </div>
            </div>
            <div class="sm:col-span-2">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="label">{{ __('Kepekatan latar') }}</span>
                    <span class="text-xs num text-muted">{{ $opasitas }}%</span>
                </div>
                <input type="range" min="0" max="100" step="5" wire:model.live.debounce.150ms="opasitas" class="w-full accent-[var(--accent)]">
                <p class="text-xs text-muted mt-1">{{ __('0% = tanpa latar (teks saja), 100% = latar hitam pekat.') }}</p>
            </div>
        </div>

        <x-slot:footer>
            <div @class(['grid gap-2', 'grid-cols-2' => $tayang])>
                @if ($tayang)
                    <button type="button" wire:click="matikan" class="btn btn-tint tint-merah h-11">{{ __('Matikan') }}</button>
                @endif
                <button type="button" wire:click="nyalakan" wire:loading.attr="disabled" wire:target="nyalakan"
                        class="btn btn-primary h-11" @disabled(trim($teks) === '')>
                    <x-ikon name="teks-jalan" size="16" /> {{ $tayang ? __('Perbarui') : __('Tayangkan') }}
                </button>
            </div>
            <p class="text-xs text-muted mt-2 text-center">{{ __('Tampil di semua TV cabang ini (APK ≥ 0.6.0).') }}</p>
        </x-slot:footer>
    </x-sheet>
</div>
