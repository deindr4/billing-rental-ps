{{-- Ringkasan kas laci & non-tunai satu shift. Variabel: $r (ShiftService::ringkasan) --}}
<div class="surface mb-4">
    <div class="px-4 py-2.5 border-b border-line font-medium">{{ __('Kas laci (tunai)') }}</div>
    <dl class="px-4 py-3 text-sm space-y-1.5">
        <div class="flex justify-between"><dt class="text-muted">{{ __('Kas awal') }}</dt><dd><x-rupiah :nilai="$r['kas_awal']" /></dd></div>
        <div class="flex justify-between"><dt class="text-muted">{{ __('Penjualan tunai') }}</dt><dd><x-rupiah :nilai="$r['penjualan_tunai']" /></dd></div>
        @if ($r['topup_tunai'] !== 0)
            <div class="flex justify-between"><dt class="text-muted">{{ __('Top up member (tunai)') }}</dt><dd><x-rupiah :nilai="$r['topup_tunai']" /></dd></div>
        @endif
        @if ($r['pembatalan'] !== 0)
            <div class="flex justify-between"><dt class="text-muted">{{ __('Pembatalan') }}</dt><dd class="text-danger"><x-rupiah :nilai="$r['pembatalan']" /></dd></div>
        @endif
        @if ($r['modal'] !== 0)
            <div class="flex justify-between"><dt class="text-muted">{{ __('Modal masuk') }}</dt><dd><x-rupiah :nilai="$r['modal']" /></dd></div>
        @endif
        @if ($r['prive'] !== 0)
            <div class="flex justify-between"><dt class="text-muted">{{ __('Prive owner') }}</dt><dd class="text-danger"><x-rupiah :nilai="$r['prive']" /></dd></div>
        @endif
        @if (($r['deposit'] ?? 0) !== 0)
            <div class="flex justify-between"><dt class="text-muted">{{ __('Deposit sewa Playbox (titipan)') }}</dt><dd><x-rupiah :nilai="$r['deposit']" /></dd></div>
        @endif
        @if ($r['pengeluaran'] !== 0)
            <div class="flex justify-between"><dt class="text-muted">{{ __('Pengeluaran') }}</dt><dd class="text-danger"><x-rupiah :nilai="$r['pengeluaran']" /></dd></div>
        @endif
        <div class="flex justify-between pt-1.5 border-t border-line font-semibold">
            <dt>{{ __('Kas seharusnya') }}</dt><dd><x-rupiah :nilai="$r['seharusnya']" /></dd>
        </div>
    </dl>
</div>

<div class="surface mb-4">
    <div class="px-4 py-2.5 border-b border-line font-medium">{{ __('Non-tunai') }}</div>
    <dl class="px-4 py-3 text-sm space-y-1.5">
        <div class="flex justify-between"><dt class="text-muted">QRIS</dt><dd><x-rupiah :nilai="$r['qris']" /></dd></div>
        <div class="flex justify-between"><dt class="text-muted">{{ __('Transfer') }}</dt><dd><x-rupiah :nilai="$r['transfer']" /></dd></div>
        @if ($r['qris_gateway'] > 0)
            <div class="flex justify-between"><dt class="text-muted">{{ __('QRIS online (bayar mandiri TV)') }}</dt><dd><x-rupiah :nilai="$r['qris_gateway']" /></dd></div>
        @endif
        @if ($r['saldo'] > 0)
            <div class="flex justify-between"><dt class="text-muted">{{ __('Saldo member') }}</dt><dd><x-rupiah :nilai="$r['saldo']" /></dd></div>
        @endif
        <div class="flex justify-between pt-1.5 border-t border-line text-muted">
            <dt>{{ __('Transaksi dibayar · dibatalkan') }}</dt>
            <dd class="num">{{ $r['jumlah_transaksi'] }} · {{ $r['jumlah_batal'] }}</dd>
        </div>
    </dl>
</div>
