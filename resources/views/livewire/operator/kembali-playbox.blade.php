<div class="max-w-2xl mx-auto" data-rahasia>
    @php
        $s = $sewa;
        $tagihan = $this->tagihan();
        $potong = $potongDeposit ? min($s->deposit, $tagihan) : 0;
    @endphp

    <div class="mb-4 flex items-end justify-between gap-3">
        <div>
            <div class="label">Terima kembali · <span class="num">{{ $s->nomor }}</span></div>
            <h1 class="text-xl font-semibold tracking-tight">{{ $s->playbox->kode }} · {{ $s->penyewa?->nama }}</h1>
            <p class="text-sm text-muted">{{ $s->labelDurasi() }} · jatuh tempo {{ $s->jatuh_tempo->format('d/m H:i') }}
                @if ($menitTelat > 0) · <span class="text-danger">telat {{ intdiv($menitTelat, 60) }} j {{ $menitTelat % 60 }} m</span>@endif
            </p>
        </div>
        <a href="{{ route('playbox') }}" wire:navigate class="btn h-9 px-3 text-sm">Kembali</a>
    </div>

    @if ($hasil)
        <div class="surface p-5 space-y-3">
            <div class="text-lg font-semibold text-st-kosong">Playbox diterima kembali</div>
            <dl class="text-sm space-y-1.5">
                <div class="flex justify-between"><dt class="text-muted">Tagihan denda & ganti rugi</dt><dd><x-rupiah :nilai="$hasil['tagihan']" /></dd></div>
                @if ($s->deposit)
                    <div class="flex justify-between font-semibold text-base"><dt>Deposit dikembalikan ke penyewa</dt><dd><x-rupiah :nilai="$hasil['deposit_kembali']" /></dd></div>
                @endif
                @if ($hasil['sisa'] > 0)
                    <div class="flex justify-between text-danger"><dt>Sisa tagihan (bayar di dialog Pembayaran)</dt><dd><x-rupiah :nilai="$hasil['sisa']" /></dd></div>
                @endif
                <div class="flex justify-between"><dt class="text-muted">Status unit</dt><dd>{{ \App\Models\Playbox::STATUS[$hasil['status_unit']] ?? $hasil['status_unit'] }}</dd></div>
            </dl>
            <p class="text-sm text-muted">Kembalikan juga jaminan: {{ collect($s->jaminan)->map(fn ($j) => \App\Models\SewaPlaybox::JAMINAN[$j['jenis']].($j['keterangan'] ? ' ('.$j['keterangan'].')' : ''))->implode(', ') ?: '-' }}.</p>
            <div class="flex gap-2">
                @if ($hasil['sisa'] > 0)
                    <button type="button" wire:click="$dispatch('buka-pembayaran', { transaksiId: '{{ $hasil['transaksi_id'] }}' })" class="btn btn-primary flex-1 h-11">Bayar sisa</button>
                @endif
                <a href="{{ route('playbox') }}" wire:navigate class="btn flex-1 h-11">Selesai</a>
            </div>
        </div>
    @else
        <div class="surface p-4 space-y-4">
            <div>
                <div class="text-sm font-medium mb-2">Checklist kembali <span class="text-muted font-normal">(dibandingkan saat keluar)</span></div>
                <div class="space-y-1.5">
                    @foreach ($checklist as $i => $c)
                        <div wire:key="ck-{{ $i }}" @class(['grid grid-cols-12 items-center gap-2 rounded-md px-2 py-1.5', 'bg-danger/10' => $c['kondisi'] !== 'baik'])>
                            <span class="col-span-4 text-sm truncate">{{ $c['nama'] }} <span class="text-muted">· keluar {{ $c['keluar'] }}</span></span>
                            <input type="number" min="0" wire:model.live="checklist.{{ $i }}.jumlah" class="input num col-span-2 h-9" title="Jumlah kembali">
                            <select wire:model.live="checklist.{{ $i }}.kondisi" class="input col-span-3 h-9">
                                @foreach (\App\Models\SewaPlaybox::KONDISI as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                            </select>
                            <input type="number" min="0" step="1000" wire:model.live="checklist.{{ $i }}.biaya" class="input num col-span-3 h-9" title="Biaya ganti" @disabled($c['kondisi'] === 'baik')>
                        </div>
                    @endforeach
                </div>
                <p class="text-xs text-muted mt-1">Biaya bawaan dari harga ganti kelengkapan (Admin → Playbox), bisa diubah.</p>
            </div>

            <div>
                <label class="block text-sm mb-1.5">Foto kondisi saat kembali</label>
                <input type="file" accept="image/*" capture="environment" multiple wire:model="fotoKondisi" class="block w-full text-sm">
                <div wire:loading wire:target="fotoKondisi" class="label mt-1">Mengunggah…</div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-sm mb-1.5">Denda telat {{ $menitTelat > 0 ? '(otomatis)' : '' }}</label>
                    <input type="number" min="0" step="1000" wire:model.live="denda" class="input num">
                </div>
                <div>
                    <label class="block text-sm mb-1.5">Catatan</label>
                    <input type="text" wire:model="catatan" class="input" maxlength="300">
                </div>
            </div>

            <dl class="rounded-md border border-line px-3 py-2 text-sm space-y-1.5">
                <div class="flex justify-between"><dt>Tagihan denda & ganti rugi</dt><dd><x-rupiah :nilai="$tagihan" class="font-semibold" /></dd></div>
                @if ($s->deposit)
                    <div class="flex justify-between"><dt class="text-muted">Deposit penyewa</dt><dd><x-rupiah :nilai="$s->deposit" /></dd></div>
                    <label class="flex items-center gap-2"><input type="checkbox" wire:model.live="potongDeposit"> Potong tagihan dari deposit</label>
                    <div class="flex justify-between font-semibold"><dt>Deposit dikembalikan</dt><dd><x-rupiah :nilai="$s->deposit - $potong" /></dd></div>
                @endif
                @if ($potong > 0 && $tagihan > $potong)
                    <div class="flex items-center justify-between gap-2 text-danger">
                        <dt>Sisa <x-rupiah :nilai="$tagihan - $potong" /> dibayar</dt>
                        <dd><select wire:model="metodeSisa" class="input h-8 w-32">
                            <option value="tunai">Tunai</option><option value="qris">QRIS</option><option value="transfer">Transfer</option>
                        </select></dd>
                    </div>
                @endif
            </dl>

            <x-confirm-button action="simpan" title="Terima kembali {{ $s->playbox->kode }}?"
                              text="Sewa selesai, tagihan dibuat & deposit diselesaikan. Pastikan jaminan dikembalikan ke penyewa."
                              confirm-text="Ya, terima" class="btn-primary w-full h-11">Terima kembali</x-confirm-button>
        </div>
    @endif

    <livewire:operator.pembayaran />
</div>
