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
            <h1 class="text-xl font-semibold">{{ __('Transaksi') }}</h1>
            <p class="text-sm text-muted">
                {{ __(':n transaksi', ['n' => (int) $ringkasan->jumlah]) }} ·
                {{ __('omzet lunas') }} <x-rupiah :nilai="$ringkasan->omzet" class="text-fg" /> ·
                {{ __(':n belum bayar', ['n' => (int) $ringkasan->belum_bayar]) }} ·
                {{ __(':n dibatalkan', ['n' => (int) $ringkasan->dibatalkan]) }}
            </p>
        </div>
    </div>

    {{-- Filter --}}
    <div class="surface p-3 mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
        <input type="search" wire:model.live.debounce.300ms="cari" class="input lg:col-span-2"
               placeholder="{{ __('Cari nomor / pelanggan') }}">
        <input type="date" wire:model.live="dari" class="input num" aria-label="{{ __('Dari tanggal') }}">
        <input type="date" wire:model.live="sampai" class="input num" aria-label="{{ __('Sampai tanggal') }}">
        <select wire:model.live="jenis" class="input" aria-label="{{ __('Jenis') }}">
            <option value="">{{ __('Semua jenis') }}</option>
            @foreach (\App\Livewire\Operator\DaftarTransaksi::JENIS as $kode => $nama)
                <option value="{{ $kode }}">{{ __($nama) }}</option>
            @endforeach
        </select>
        <select wire:model.live="status" class="input" aria-label="{{ __('Status') }}">
            <option value="">{{ __('Semua status') }}</option>
            @foreach (\App\Livewire\Operator\DaftarTransaksi::STATUS as $kode => $nama)
                <option value="{{ $kode }}">{{ __($nama) }}</option>
            @endforeach
        </select>
        <div class="flex gap-2 lg:col-span-6">
            <button type="button" wire:click="hariIni" class="btn h-8 px-3 text-sm">{{ __('Hari ini') }}</button>
            <button type="button" wire:click="resetFilter" class="btn btn-ghost h-8 px-3 text-sm text-muted">{{ __('Reset filter') }}</button>
        </div>
    </div>

    {{-- Daftar --}}
    @if ($daftar->isEmpty())
        <div class="surface p-8 text-center text-muted">{{ __('Tidak ada transaksi pada filter ini.') }}</div>
    @else
        <div class="surface overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted border-b border-line">
                    <tr>
                        <th class="px-3 py-2 font-medium">{{ __('Nomor') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Waktu') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Unit / Pelanggan') }}</th>
                        <th class="px-3 py-2 font-medium hidden md:table-cell">{{ __('Kasir') }}</th>
                        <th class="px-3 py-2 font-medium text-right">{{ __('Total') }}</th>
                        <th class="px-3 py-2 font-medium">{{ __('Status') }}</th>
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
                                <div class="truncate max-w-48">{{ $trx->unit?->nama ?? __(\App\Livewire\Operator\DaftarTransaksi::JENIS[$trx->jenis] ?? $trx->jenis) }}</div>
                                <div class="text-xs text-muted truncate max-w-48">{{ $trx->pelanggan_nama ?: __('Tamu') }}</div>
                            </td>
                            <td class="px-3 py-2 hidden md:table-cell text-muted">{{ $trx->user?->name }}</td>
                            <td @class(['px-3 py-2 text-right whitespace-nowrap', 'line-through text-muted' => $trx->isDibatalkan()])>
                                <x-rupiah :nilai="$trx->total" />
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <span class="badge {{ $warnaStatus[$trx->status] ?? '' }}">
                                    {{ __(\App\Livewire\Operator\DaftarTransaksi::STATUS[$trx->status] ?? $trx->status) }}
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
                <button type="button" wire:click="previousPage" class="btn h-9" @disabled($daftar->onFirstPage())>{{ __('Sebelumnya') }}</button>
                <button type="button" wire:click="nextPage" class="btn h-9" @disabled(! $daftar->hasMorePages())>{{ __('Berikutnya') }}</button>
            </div>
        @endif
    @endif

    {{-- Dialog --}}
    <livewire:operator.detail-transaksi />
    <livewire:operator.pembayaran />
</div>
