<div>
    @php
        $warnaStatus = [
            'lunas' => 'text-accent',
            'belum_bayar' => 'text-st-hampir',
            'dibatalkan' => 'text-danger',
        ];
    @endphp

    {{-- Judul + ringkasan --}}
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
            <h1 class="text-xl font-semibold">Transaksi</h1>
            <p class="text-sm text-muted">
                <span class="num">{{ (int) $ringkasan->jumlah }}</span> transaksi ·
                omzet lunas <x-rupiah :nilai="$ringkasan->omzet" class="text-fg" /> ·
                <span class="num">{{ (int) $ringkasan->belum_bayar }}</span> belum bayar ·
                <span class="num">{{ (int) $ringkasan->dibatalkan }}</span> dibatalkan
            </p>
        </div>
    </div>

    {{-- Filter --}}
    <div class="surface p-3 mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
        <input type="search" wire:model.live.debounce.300ms="cari" class="input lg:col-span-2"
               placeholder="Cari nomor / pelanggan">
        <input type="date" wire:model.live="dari" class="input num" aria-label="Dari tanggal">
        <input type="date" wire:model.live="sampai" class="input num" aria-label="Sampai tanggal">
        <select wire:model.live="jenis" class="input" aria-label="Jenis">
            <option value="">Semua jenis</option>
            @foreach (\App\Livewire\Operator\DaftarTransaksi::JENIS as $kode => $nama)
                <option value="{{ $kode }}">{{ $nama }}</option>
            @endforeach
        </select>
        <select wire:model.live="status" class="input" aria-label="Status">
            <option value="">Semua status</option>
            @foreach (\App\Livewire\Operator\DaftarTransaksi::STATUS as $kode => $nama)
                <option value="{{ $kode }}">{{ $nama }}</option>
            @endforeach
        </select>
        <div class="flex gap-2 lg:col-span-6">
            <button type="button" wire:click="hariIni" class="btn h-8 px-3 text-sm">Hari ini</button>
            <button type="button" wire:click="resetFilter" class="btn btn-ghost h-8 px-3 text-sm text-muted">Reset filter</button>
        </div>
    </div>

    {{-- Daftar --}}
    @if ($daftar->isEmpty())
        <div class="surface p-8 text-center text-muted">Tidak ada transaksi pada filter ini.</div>
    @else
        <div class="surface overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted border-b border-line">
                    <tr>
                        <th class="px-3 py-2 font-medium">Nomor</th>
                        <th class="px-3 py-2 font-medium">Waktu</th>
                        <th class="px-3 py-2 font-medium">Unit / Pelanggan</th>
                        <th class="px-3 py-2 font-medium hidden md:table-cell">Kasir</th>
                        <th class="px-3 py-2 font-medium text-right">Total</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($daftar as $trx)
                        <tr wire:key="trx-{{ $trx->id }}"
                            wire:click="$dispatch('buka-detail-transaksi', { transaksiId: '{{ $trx->id }}' })"
                            class="cursor-pointer hover:bg-surface-2">
                            <td class="px-3 py-2 num whitespace-nowrap">{{ $trx->nomor }}</td>
                            <td class="px-3 py-2 num whitespace-nowrap text-muted">{{ $trx->created_at->format('d/m H:i') }}</td>
                            <td class="px-3 py-2">
                                <div class="truncate max-w-48">{{ $trx->unit?->nama ?? \App\Livewire\Operator\DaftarTransaksi::JENIS[$trx->jenis] ?? $trx->jenis }}</div>
                                <div class="text-xs text-muted truncate max-w-48">{{ $trx->pelanggan_nama ?: 'Tamu' }}</div>
                            </td>
                            <td class="px-3 py-2 hidden md:table-cell text-muted">{{ $trx->user?->name }}</td>
                            <td @class(['px-3 py-2 text-right whitespace-nowrap', 'line-through text-muted' => $trx->isDibatalkan()])>
                                <x-rupiah :nilai="$trx->total" />
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <span class="badge {{ $warnaStatus[$trx->status] ?? '' }}">
                                    {{ \App\Livewire\Operator\DaftarTransaksi::STATUS[$trx->status] ?? $trx->status }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Navigasi halaman --}}
        @if (! $daftar->onFirstPage() || $daftar->hasMorePages())
            <div class="flex justify-between gap-2 mt-3">
                <button type="button" wire:click="previousPage" class="btn h-9" @disabled($daftar->onFirstPage())>Sebelumnya</button>
                <button type="button" wire:click="nextPage" class="btn h-9" @disabled(! $daftar->hasMorePages())>Berikutnya</button>
            </div>
        @endif
    @endif

    {{-- Dialog --}}
    <livewire:operator.detail-transaksi />
    <livewire:operator.pembayaran />
</div>
