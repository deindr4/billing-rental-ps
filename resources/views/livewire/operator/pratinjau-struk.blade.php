<div>
    <x-sheet wire:model="buka" :judul="__('Pratinjau Struk')">
        @if ($this->transaksi)
            @php $struk = $this->struk; @endphp

            {{-- Kertas thermal: lebar mengikuti jumlah kolom printer --}}
            <div class="rounded-md bg-surface-2 p-4 flex justify-center">
                <div class="bg-white text-black shadow-md px-3 py-4 font-mono text-[12px] leading-[1.35] overflow-x-auto"
                     style="width: calc({{ $struk['kolom'] }}ch + 1.5rem); max-width: 100%;">
                    @foreach ($struk['baris'] as $r)
                        <div @class([
                                'whitespace-pre',
                                'font-bold' => $r['tebal'],
                                'text-center' => $r['rata'] === 'tengah',
                                'text-[2em] leading-tight' => $r['besar'],
                            ])>{{ $r['t'] === '' ? ' ' : $r['t'] }}</div>
                    @endforeach
                    {{-- Sobekan kertas --}}
                    <div class="mt-3 border-t border-dashed border-black/30"></div>
                </div>
            </div>

            <p class="text-xs text-muted text-center mt-3">
                {{ __('Kertas :lebar mm · Cetak lewat aplikasi RawBT ke printer Bluetooth', ['lebar' => $struk['lebar']]) }}
            </p>

            <x-slot:footer>
                <div class="grid grid-cols-3 gap-2">
                    <button type="button" class="btn" @click="buka = false">{{ __('Tutup') }}</button>
                    <a href="{{ route('struk.nota', $this->transaksi->id) }}" target="_blank" class="btn btn-tint tint-biru">{{ __('Nota A4') }}</a>
                    <a href="{{ route('struk.thermal', $this->transaksi->id) }}" class="btn btn-primary">{{ __('Cetak') }}</a>
                </div>
            </x-slot:footer>
        @endif
    </x-sheet>
</div>
