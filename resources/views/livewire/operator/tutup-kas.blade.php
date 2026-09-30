<div class="max-w-lg mx-auto">
    @php
        $r = $this->ringkasan;
        $selisih = $this->selisih();
    @endphp

    <div class="mb-4">
        <h1 class="text-xl font-semibold">Tutup Kas</h1>
        <p class="text-sm text-muted">
            <span class="num">{{ $this->shift->nomor }}</span> · dibuka {{ $this->shift->dibuka_pada->format('d/m H:i') }}
        </p>
    </div>

    {{-- Peringatan --}}
    @if ($r['sesi_aktif'] > 0 || $r['menunggu_bayar'] > 0)
        <div class="rounded-md border border-st-hampir px-3 py-2 mb-4 text-sm">
            @if ($r['sesi_aktif'] > 0)
                <div><span class="num">{{ $r['sesi_aktif'] }}</span> sesi masih berjalan. Sesi tetap berjalan, pembayarannya masuk ke shift berikutnya.</div>
            @endif
            @if ($r['menunggu_bayar'] > 0)
                <div><span class="num">{{ $r['menunggu_bayar'] }}</span> unit masih menunggu pembayaran.</div>
            @endif
        </div>
    @endif

    {{-- Ringkasan kas laci --}}
    <div class="surface mb-4">
        <div class="px-4 py-2.5 border-b border-line font-medium">Kas laci (tunai)</div>
        <dl class="px-4 py-3 text-sm space-y-1.5">
            <div class="flex justify-between"><dt class="text-muted">Kas awal</dt><dd><x-rupiah :nilai="$r['kas_awal']" /></dd></div>
            <div class="flex justify-between"><dt class="text-muted">Penjualan tunai</dt><dd><x-rupiah :nilai="$r['penjualan_tunai']" /></dd></div>
            @if ($r['topup_tunai'] !== 0)
                <div class="flex justify-between"><dt class="text-muted">Top up member (tunai)</dt><dd><x-rupiah :nilai="$r['topup_tunai']" /></dd></div>
            @endif
            @if ($r['pembatalan'] !== 0)
                <div class="flex justify-between"><dt class="text-muted">Pembatalan</dt><dd class="text-danger"><x-rupiah :nilai="$r['pembatalan']" /></dd></div>
            @endif
            @if ($r['modal'] !== 0)
                <div class="flex justify-between"><dt class="text-muted">Modal masuk</dt><dd><x-rupiah :nilai="$r['modal']" /></dd></div>
            @endif
            @if ($r['prive'] !== 0)
                <div class="flex justify-between"><dt class="text-muted">Prive owner</dt><dd class="text-danger"><x-rupiah :nilai="$r['prive']" /></dd></div>
            @endif
            @if ($r['pengeluaran'] !== 0)
                <div class="flex justify-between"><dt class="text-muted">Pengeluaran</dt><dd class="text-danger"><x-rupiah :nilai="$r['pengeluaran']" /></dd></div>
            @endif
            <div class="flex justify-between pt-1.5 border-t border-line font-semibold">
                <dt>Kas seharusnya</dt><dd><x-rupiah :nilai="$r['seharusnya']" /></dd>
            </div>
        </dl>
    </div>

    {{-- Non tunai --}}
    <div class="surface mb-4">
        <div class="px-4 py-2.5 border-b border-line font-medium">Non-tunai</div>
        <dl class="px-4 py-3 text-sm space-y-1.5">
            <div class="flex justify-between"><dt class="text-muted">QRIS</dt><dd><x-rupiah :nilai="$r['qris']" /></dd></div>
            <div class="flex justify-between"><dt class="text-muted">Transfer</dt><dd><x-rupiah :nilai="$r['transfer']" /></dd></div>
            @if ($r['qris_gateway'] > 0)
                <div class="flex justify-between"><dt class="text-muted">QRIS online (bayar mandiri TV)</dt><dd><x-rupiah :nilai="$r['qris_gateway']" /></dd></div>
            @endif
            @if ($r['saldo'] > 0)
                <div class="flex justify-between"><dt class="text-muted">Saldo member</dt><dd><x-rupiah :nilai="$r['saldo']" /></dd></div>
            @endif
            <div class="flex justify-between pt-1.5 border-t border-line text-muted">
                <dt>Transaksi dibayar · dibatalkan</dt>
                <dd class="num">{{ $r['jumlah_transaksi'] }} · {{ $r['jumlah_batal'] }}</dd>
            </div>
        </dl>
    </div>

    {{-- Input kas fisik --}}
    <div class="surface p-4 space-y-4">
        <div>
            <label for="kasFisik" class="block text-sm mb-1.5">Uang di laci (hitung fisik)</label>
            <x-input-uang id="kasFisik" wire:model.live="kasFisik" class="text-lg" />
            @error('kasFisik')
                <p class="text-sm text-danger mt-1.5">{{ $message }}</p>
            @enderror
        </div>

        @if ($selisih !== null)
            <div @class([
                'rounded-md border px-3 py-2 flex justify-between items-baseline',
                'border-line' => $selisih === 0,
                'border-danger text-danger' => $selisih !== 0,
            ])>
                <span class="text-sm">{{ $selisih === 0 ? 'Kas cocok' : ($selisih > 0 ? 'Kas lebih' : 'Kas kurang') }}</span>
                <x-rupiah :nilai="abs($selisih)" class="text-xl font-semibold" />
            </div>
        @endif

        @if ($selisih !== null && $selisih !== 0)
            <div>
                <label for="catatan" class="block text-sm mb-1.5">Keterangan selisih</label>
                <textarea id="catatan" wire:model="catatan" rows="2" class="input h-auto py-2"
                          placeholder="Contoh: kembalian salah Rp 2.000"></textarea>
                @error('catatan')
                    <p class="text-sm text-danger mt-1.5">{{ $message }}</p>
                @enderror
            </div>
        @endif

        <x-confirm-button action="tutup"
                          title="Tutup shift sekarang?"
                          text="Setelah ditutup, transaksi baru harus memakai shift baru."
                          confirm-text="Ya, tutup kas"
                          danger
                          class="w-full h-11"
                          :disabled="$kasFisik === null">
            Tutup Kas
        </x-confirm-button>
    </div>
</div>
