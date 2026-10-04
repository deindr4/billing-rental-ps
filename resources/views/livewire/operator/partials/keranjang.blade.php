{{-- Isi keranjang POS (dipakai di panel desktop dan sheet HP) --}}
<div class="space-y-4">
    {{-- Tujuan --}}
    <div>
        <label class="block text-sm mb-1.5">Tujuan</label>
        <select wire:model.live="tujuanUnitId" class="input">
            <option value="">Bayar langsung</option>
            @foreach ($this->unitTujuan as $s)
                <option value="{{ $s->unit_id }}">
                    Gabung ke {{ $s->unit->nama }}{{ $s->transaksi?->pelanggan_nama ? ' · '.$s->transaksi->pelanggan_nama : '' }}
                </option>
            @endforeach
        </select>
    </div>

    {{-- Isi tagihan unit tujuan (sama dengan rincian di layar TV): cocokkan dulu sebelum menambah --}}
    @if ($tagihan = $this->tagihanTujuan)
        @php
            $sesiT = $this->sesiTujuan;
            $aktifItem = $tagihan->items->filter(fn ($i) => $i->subtotal > 0 || $i->jenis === \App\Models\TransaksiItem::JENIS_PRODUK);
        @endphp
        <div class="rounded-md border border-line" wire:key="tagihan-{{ $tagihan->id }}-{{ $tagihan->items->count() }}">
            <div class="px-3 py-2 border-b border-line flex items-center justify-between gap-2">
                <span class="text-sm font-medium">Sudah di tagihan {{ $sesiT->unit->nama }}</span>
                <span @class(['label', 'text-st-hampir' => $sesiT->status === 'selesai', 'text-st-main' => $sesiT->status !== 'selesai'])>
                    {{ $sesiT->status === 'selesai' ? 'Menunggu bayar' : 'Sedang main' }}
                </span>
            </div>
            @if ($aktifItem->isEmpty())
                <p class="px-3 py-3 text-sm text-muted">Belum ada item.</p>
            @else
                <ul class="divide-y divide-line text-sm max-h-56 overflow-y-auto">
                    @foreach ($aktifItem as $item)
                        @php
                            $fnb = $item->jenis === \App\Models\TransaksiItem::JENIS_PRODUK;
                            $dibatalkan = $fnb && $item->qty < 1;
                            $pin = $fnb && ! $dibatalkan && $this->batalButuhPin($item);
                            $ketPin = $pin ? ' Butuh PIN supervisor/owner.' : '';
                        @endphp
                        <li class="px-3 py-1.5 flex items-start justify-between gap-2" wire:key="item-{{ $item->id }}-{{ $item->qty }}">
                            <span class="min-w-0">
                                <span @class(['block truncate', 'text-muted' => ! $fnb || $dibatalkan, 'line-through' => $dibatalkan])>{{ $item->nama }}</span>
                                {{-- Jumlah di baris kecil supaya tidak ikut terpotong nama yang panjang --}}
                                <span class="block text-xs text-muted num">{{ $item->created_at->format('H:i') }}{{ $item->qty > 1 ? ' · ×'.$item->qty : '' }}</span>
                            </span>
                            <span class="shrink-0 flex items-center gap-1.5">
                                <x-rupiah :nilai="$item->subtotal" class="{{ $fnb && ! $dibatalkan ? '' : 'text-muted' }}" />
                                {{-- Salah order: batalkan 1 / semua (stok kembali, TV ikut berubah) --}}
                                @if ($fnb && ! $dibatalkan)
                                    <x-confirm-button action="batalItem" :params="[$item->id, 1]"
                                                      title="Batalkan 1× {{ $item->nama }}?"
                                                      text="Dihapus dari tagihan {{ $sesiT->unit->nama }} & stok dikembalikan.{{ $ketPin }}"
                                                      confirm-text="Ya, batalkan" reason :pin="$pin"
                                                      class="btn-tint tint-merah h-7 px-2 text-xs">
                                        {{ $item->qty > 1 ? '−1' : 'Batal' }}
                                    </x-confirm-button>
                                    @if ($item->qty > 1)
                                        <x-confirm-button action="batalItem" :params="[$item->id, $item->qty]"
                                                          title="Batalkan semua {{ $item->qty }}× {{ $item->nama }}?"
                                                          text="Dihapus dari tagihan {{ $sesiT->unit->nama }} & stok dikembalikan.{{ $ketPin }}"
                                                          confirm-text="Ya, batalkan semua" reason :pin="$pin"
                                                          class="btn-tint tint-merah h-7 px-2 text-xs">
                                            Semua
                                        </x-confirm-button>
                                    @endif
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <div class="px-3 py-2 border-t border-line text-sm space-y-0.5">
                <div class="flex justify-between">
                    <span class="text-muted">Tagihan sekarang</span>
                    <x-rupiah :nilai="$tagihan->total" class="font-medium" />
                </div>
                @if ($this->jumlahItem > 0)
                    <div class="flex justify-between font-semibold">
                        <span>Setelah ditambah</span>
                        <x-rupiah :nilai="$tagihan->total + $this->total" />
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Item --}}
    @if ($this->isiKeranjang->isEmpty())
        <p class="text-sm text-muted py-6 text-center">Keranjang kosong. Klik produk untuk menambahkan.</p>
    @else
        <ul class="divide-y divide-line rounded-md border border-line">
            @foreach ($this->isiKeranjang as $id => $baris)
                <li wire:key="krj-{{ $id }}" class="px-3 py-2">
                    <div class="flex justify-between gap-2 text-sm">
                        <span class="min-w-0 truncate">{{ $baris['produk']->nama }}</span>
                        <x-rupiah :nilai="$baris['subtotal']" class="shrink-0" />
                    </div>
                    <div class="flex items-center justify-between gap-2 mt-1.5">
                        <span class="text-xs text-muted num">@ {{ number_format($baris['produk']->harga_jual, 0, ',', '.') }}</span>
                        <div class="flex items-center gap-1">
                            <button type="button" wire:click="kurang('{{ $id }}')" class="btn h-7 w-7 px-0" aria-label="Kurangi">−</button>
                            <span class="num w-8 text-center">{{ $baris['qty'] }}</span>
                            <button type="button" wire:click="tambah('{{ $id }}')" class="btn h-7 w-7 px-0" aria-label="Tambah">+</button>
                            <button type="button" wire:click="hapus('{{ $id }}')" class="btn btn-ghost h-7 px-2 text-xs text-danger">Hapus</button>
                        </div>
                    </div>
                    @if ($baris['produk']->lacak_stok && $baris['qty'] > $baris['produk']->sisaStok())
                        <div class="text-xs text-danger mt-1">Stok hanya {{ $baris['produk']->sisaStok() }}</div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Pelanggan (hanya bayar langsung) --}}
    @if ($tujuanUnitId === '')
        <div>
            <label class="block text-sm mb-1.5">Nama pelanggan <span class="text-muted">(opsional)</span></label>
            <input type="text" wire:model="pelanggan" class="input" maxlength="100" placeholder="Tamu">
        </div>
    @endif

    {{-- Total + tombol --}}
    <div class="border-t border-line pt-3">
        <div class="flex justify-between items-baseline mb-3">
            <span class="text-muted">Total <span class="num">({{ $this->jumlahItem }} item)</span></span>
            <x-rupiah :nilai="$this->total" class="text-2xl font-semibold" />
        </div>

        <div class="grid grid-cols-3 gap-2">
            <button type="button" wire:click="kosongkan" class="btn" @disabled($this->isiKeranjang->isEmpty())>Batal</button>
            <button type="button" wire:click="proses" wire:loading.attr="disabled" wire:target="proses"
                    class="btn btn-primary col-span-2 h-11" @disabled($this->isiKeranjang->isEmpty())>
                <span wire:loading.remove wire:target="proses">{{ $tujuanUnitId === '' ? 'Bayar' : 'Tambahkan ke Tagihan' }}</span>
                <span wire:loading wire:target="proses">Memproses...</span>
            </button>
        </div>
    </div>
</div>
