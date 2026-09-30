@php
    $warna = ['menunggu' => 'var(--status-main)', 'dibayar' => 'var(--status-hampir-habis)', 'selesai' => 'var(--status-kosong)', 'kedaluwarsa' => 'var(--text-muted)', 'gagal' => 'var(--status-offline)', 'perlu_tindakan' => 'var(--status-offline)'];
    $layanan = app(\App\Services\Gateway\BayarMandiriService::class);
@endphp
<div wire:poll.10s="segarkan">
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
            <div class="label">Bayar mandiri lewat QRIS di TV</div>
            <h1 class="text-xl font-semibold tracking-tight">Pembayaran online</h1>
        </div>
        <input type="date" wire:model.live="tanggal" class="input h-10 w-40">
    </div>

    <div class="grid grid-cols-3 gap-3 mb-4">
        <div class="kartu p-3"><div class="label">Transaksi</div><div class="text-xl font-semibold num">{{ $this->ringkasan['jumlah'] }}</div></div>
        <div class="kartu p-3"><div class="label">Nominal</div><x-rupiah :nilai="$this->ringkasan['nominal']" class="text-xl font-semibold text-accent" /></div>
        <div class="kartu p-3"><div class="label">Biaya gateway</div><x-rupiah :nilai="$this->ringkasan['biaya']" class="text-xl font-semibold text-danger" /></div>
    </div>

    <div class="space-y-2">
        @forelse ($this->daftar as $p)
            <div wire:key="po-{{ $p->id }}" class="kartu kartu-status p-4 flex flex-wrap items-center gap-3" style="--warna-status: {{ $warna[$p->status] }}">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold">{{ $p->unit?->nama }}</span>
                        <span class="chip" style="color: {{ $warna[$p->status] }}; border-color: {{ $warna[$p->status] }}">{{ \App\Models\PembayaranOnline::STATUS[$p->status] }}</span>
                        <span class="chip">{{ $p->jenis === 'isi_ulang' ? 'Isi ulang' : 'Mulai' }}</span>
                        <span class="chip">{{ ucfirst($p->provider) }}</span>
                    </div>
                    <div class="text-sm text-muted mt-1">
                        <x-rupiah :nilai="$p->nominal" class="text-fg font-medium" /> · {{ $layanan->labelMenit($p->menit) }}
                        · {{ $p->created_at->format('H:i') }} · <span class="num">{{ $p->merchant_ref }}</span>
                    </div>
                    @if ($p->catatan) <div class="text-sm mt-1 {{ $p->status === 'perlu_tindakan' ? 'text-danger' : 'text-muted' }}">{{ $p->catatan }}</div> @endif
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    @if ($p->status === 'menunggu' && $p->provider === 'simulasi' && $p->masihBisaDibayar())
                        <button type="button" wire:click="simulasiBayar('{{ $p->id }}')" class="btn btn-primary h-9 px-3 text-sm">Simulasi bayar</button>
                    @endif
                    @if ($p->status === 'perlu_tindakan')
                        <select wire:model="unitTujuan.{{ $p->id }}" class="input h-9 w-auto text-sm py-0">
                            <option value="">Pilih unit kosong</option>
                            @foreach ($this->unitKosong as $u) <option value="{{ $u->id }}">{{ $u->nama }}</option> @endforeach
                        </select>
                        <button type="button" wire:click="terapkan('{{ $p->id }}')" class="btn btn-primary h-9 px-3 text-sm">Mulai di unit ini</button>
                        <x-confirm-button action="tandaiDiurus" :params="[$p->id]" title="Tandai sudah diurus?" text="Misal uang dikembalikan tunai atau dipakai lain waktu." reason class="h-9 px-3 text-sm">Sudah diurus</x-confirm-button>
                    @endif
                </div>
            </div>
        @empty
            <div class="kartu p-8 text-center text-muted">
                Belum ada pembayaran online. Aktifkan di Admin → Pengaturan → Pembayaran online & Operasional → Bayar mandiri di TV.
            </div>
        @endforelse
    </div>
</div>
