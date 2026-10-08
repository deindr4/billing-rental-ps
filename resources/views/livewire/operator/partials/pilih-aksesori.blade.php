{{--
    Pemilih sewa aksesori (− jumlah +). Variabel: $daftar (Collection Aksesori), $pilihan ([id => qty]).
    Komponen wajib punya method ubahAksesori(string $id, int $ubah).
--}}
<div>
    <div class="label mb-2">Sewa aksesori <span class="normal-case tracking-normal">(opsional)</span></div>
    <div class="space-y-1.5">
        @foreach ($daftar as $a)
            @php
                $qty = (int) ($pilihan[$a->id] ?? 0);
                $sisa = $a->tersedia();
            @endphp
            <div wire:key="aks-{{ $a->id }}"
                 @class(['flex items-center justify-between gap-3 rounded-md border px-3 py-2', 'border-accent' => $qty > 0, 'border-line' => $qty === 0])>
                <div class="min-w-0">
                    <div class="font-medium truncate">{{ $a->nama }}</div>
                    <div class="text-xs text-muted">
                        <span class="num">{{ $a->labelHarga() }}</span> ·
                        <span @class(['text-danger' => $sisa === 0])>{{ $sisa === 0 ? 'habis disewa' : "tersedia {$sisa}" }}</span>
                    </div>
                </div>
                <div class="flex items-center gap-1.5 shrink-0">
                    <button type="button" wire:click="ubahAksesori('{{ $a->id }}', -1)" class="btn btn-ikon h-8 w-8" @disabled($qty === 0) aria-label="Kurangi">−</button>
                    <span class="num w-6 text-center">{{ $qty }}</span>
                    <button type="button" wire:click="ubahAksesori('{{ $a->id }}', 1)" class="btn btn-ikon h-8 w-8" @disabled($qty >= $sisa) aria-label="Tambah">+</button>
                </div>
            </div>
        @endforeach
    </div>
</div>
