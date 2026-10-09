<div class="lg:grid lg:grid-cols-[1fr_360px] lg:gap-4">
    {{-- ============ Kiri: produk ============ --}}
    <div class="min-w-0">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <div class="flex items-center gap-2">
                <h1 class="text-xl font-semibold">{{ __('POS') }}</h1>
                <a href="{{ route('stok') }}" wire:navigate class="btn btn-ghost h-8 px-3 text-sm text-muted">{{ __('Kelola Stok') }}</a>
            </div>
            <input type="search"
                   wire:model.live.debounce.250ms="cari"
                   wire:keydown.enter.prevent="scan"
                   class="input sm:w-72"
                   placeholder="{{ __('Cari / scan barcode lalu Enter') }}"
                   autofocus>
        </div>

        {{-- Kategori --}}
        <div class="flex gap-2 overflow-x-auto pb-1 mb-3">
            <button type="button" wire:click="$set('kategoriId', '')"
                    @class(['btn h-8 px-3 text-sm shrink-0', 'btn-primary' => $kategoriId === ''])>{{ __('Semua') }}</button>
            @foreach ($this->kategori as $k)
                <button type="button" wire:key="kat-{{ $k->id }}" wire:click="$set('kategoriId', '{{ $k->id }}')"
                        @class(['btn h-8 px-3 text-sm shrink-0', 'btn-primary' => $kategoriId === $k->id])>{{ $k->nama }}</button>
            @endforeach
        </div>

        {{-- Grid produk --}}
        @if ($this->produk->isEmpty())
            <div class="surface p-8 text-center text-muted">{{ __('Produk tidak ditemukan.') }}</div>
        @else
            <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-2">
                @foreach ($this->produk as $p)
                    @php $habis = $p->lacak_stok && $p->sisaStok() <= 0; @endphp
                    <button type="button" wire:key="prd-{{ $p->id }}" wire:click="tambah('{{ $p->id }}')"
                            @class([
                                'surface p-3 text-left flex flex-col gap-1 hover:border-accent relative',
                                'opacity-50' => $habis,
                            ])>
                        @if (isset($keranjang[$p->id]))
                            <span class="absolute top-2 right-2 badge text-accent num">{{ $keranjang[$p->id] }}</span>
                        @endif
                        <span class="font-medium leading-snug pr-8">{{ $p->nama }}</span>
                        <x-rupiah :nilai="$p->harga_jual" class="text-sm" />
                        @if ($p->lacak_stok)
                            <span @class([
                                'text-xs',
                                'text-danger' => $habis,
                                'text-st-hampir' => ! $habis && $p->stokMenipis(),
                                'text-muted' => ! $p->stokMenipis(),
                            ])>
                                {{ $habis ? __('Stok habis') : __('Stok :n', ['n' => $p->sisaStok()]) }}
                            </span>
                        @endif
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ============ Kanan: keranjang (desktop) ============ --}}
    <aside class="hidden lg:block">
        <div class="surface p-4 sticky top-18">
            <h2 class="font-semibold mb-3">{{ __('Keranjang') }}</h2>
            @include('livewire.operator.partials.keranjang')
        </div>
    </aside>

    {{-- ============ Bar keranjang (HP) ============ --}}
    @if ($this->jumlahItem > 0 || $this->sesiTujuan)
        <div class="lg:hidden fixed inset-x-0 bottom-16 z-20 px-4" style="margin-bottom: env(safe-area-inset-bottom, 0px);">
            <button type="button" wire:click="$set('keranjangBuka', true)"
                    class="btn btn-primary w-full h-12 justify-between">
                @if ($this->jumlahItem > 0)
                    <span>{{ __('Keranjang · :n item', ['n' => $this->jumlahItem]) }}</span>
                    <x-rupiah :nilai="$this->total" class="font-semibold" />
                @else
                    {{-- Dari Kelola Sesi: lihat dulu isi tagihan unit --}}
                    <span>{{ __('Lihat tagihan :unit', ['unit' => $this->sesiTujuan->unit->nama]) }}</span>
                    <x-rupiah :nilai="$this->tagihanTujuan?->total ?? 0" class="font-semibold" />
                @endif
            </button>
        </div>
    @endif

    <div class="lg:hidden">
        <x-sheet wire:model="keranjangBuka" :judul="__('Keranjang')">
            @include('livewire.operator.partials.keranjang')
        </x-sheet>
    </div>

    {{-- Dialog pembayaran --}}
    <livewire:operator.pembayaran />
</div>
