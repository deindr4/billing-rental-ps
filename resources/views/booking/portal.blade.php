@php
    $c = $this->cabang;
    $a = $this->aturan;
    $rp = fn ($n) => 'Rp '.number_format((int) $n, 0, ',', '.');
    $wa = $c->telepon ? 'https://wa.me/'.preg_replace('/^0/', '62', preg_replace('/\D/', '', $c->telepon)) : null;
    $statusWarna = ['menunggu' => 'var(--status-hampir-habis)', 'dikonfirmasi' => 'var(--status-kosong)', 'checkin' => 'var(--status-main)', 'batal' => 'var(--status-offline)', 'tidak_datang' => 'var(--status-offline)'];
@endphp
<div class="max-w-lg mx-auto px-4 py-6 space-y-5">
    {{-- Kepala --}}
    <header class="flex items-center gap-3">
        @if ($this->logo())
            <img src="{{ $this->logo() }}" alt="" class="h-12 w-12 rounded-lg object-contain">
        @endif
        <div class="min-w-0">
            <div class="text-xl font-bold leading-tight">{{ $c->tenant?->nama }}</div>
            <div class="label">{{ $c->nama }} · Booking online</div>
        </div>
        <button type="button" wire:click="$toggle('modeCek')" class="ml-auto btn h-9 px-3 text-sm">{{ $modeCek ? 'Booking baru' : 'Cek booking' }}</button>
    </header>

    @if ($c->alamat || $a['aktif'])
        <div class="text-sm text-muted">
            {{ $c->alamat }}{{ $c->alamat ? ' · ' : '' }}Buka {{ $a['jam_buka'] }}–{{ $a['jam_tutup'] }}
        </div>
    @endif

    {{-- ================= Hasil booking ================= --}}
    @if ($b = $this->booking)
        <section class="kartu p-5 space-y-4">
            <div class="text-center">
                <div class="label">Kode booking</div>
                <div class="text-4xl font-bold num tracking-widest mt-1" style="color: var(--accent)">{{ $b->kode }}</div>
                <span class="chip mt-2" style="color: {{ $statusWarna[$b->status] }}; border-color: {{ $statusWarna[$b->status] }}">{{ \App\Models\Booking::STATUS[$b->status] }}</span>
            </div>
            <dl class="text-sm space-y-1.5">
                <div class="flex justify-between"><dt class="text-muted">Nama</dt><dd>{{ $b->nama }}</dd></div>
                <div class="flex justify-between"><dt class="text-muted">Waktu</dt><dd>{{ $b->mulai_pada->translatedFormat('l, d M Y') }} · {{ $b->mulai_pada->format('H:i') }}–{{ $b->selesai_pada->format('H:i') }}</dd></div>
                <div class="flex justify-between"><dt class="text-muted">Unit</dt><dd>{{ $b->unit?->nama ?? '-' }}</dd></div>
                <div class="flex justify-between"><dt class="text-muted">Perkiraan biaya</dt><dd><x-rupiah :nilai="$b->perkiraan_harga" /></dd></div>
            </dl>
            @if ($b->status === 'menunggu')
                <p class="text-sm rounded-md px-3 py-2" style="background: color-mix(in srgb, var(--status-hampir-habis) 12%, transparent)">
                    Booking diterima dan menunggu konfirmasi kasir. Simpan kode di atas.
                </p>
            @endif
            @if ($b->isAktif())
                <p class="text-xs text-muted">Datang paling lambat {{ $a['toleransi_menit'] }} menit setelah jam mulai, tunjukkan kode ke kasir. Bayar di kasir.</p>
            @endif
            <div class="grid gap-2">
                @if ($wa)
                    <a href="{{ $wa }}?text={{ urlencode("Halo, saya booking {$b->kode} atas nama {$b->nama}, ".$b->mulai_pada->translatedFormat('d M H:i').'.') }}"
                       target="_blank" class="btn btn-primary h-11">Kirim kode ke WhatsApp rental</a>
                @endif
                @if ($b->isAktif() && $b->mulai_pada->gt(now()->addMinutes(30)))
                    <button type="button" wire:click="batalkan" wire:confirm="Batalkan booking {{ $b->kode }}?" class="btn h-11 text-danger">Batalkan booking</button>
                @endif
                @error('batal') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                <button type="button" wire:click="bookingBaru" class="btn btn-ghost h-10 text-sm">Buat booking lain</button>
            </div>
        </section>

    {{-- ================= Cek booking ================= --}}
    @elseif ($modeCek)
        <form wire:submit="cek" class="kartu p-5 space-y-3">
            <div class="font-semibold">Cek status booking</div>
            <input type="text" wire:model="cekKode" class="input num uppercase" placeholder="Kode booking (BK...)" maxlength="12">
            @error('cekKode') <p class="text-sm text-danger">{{ $message }}</p> @enderror
            <input type="tel" inputmode="tel" wire:model="cekTelepon" class="input num" placeholder="Nomor WhatsApp" maxlength="20">
            @error('cekTelepon') <p class="text-sm text-danger">{{ $message }}</p> @enderror
            <button type="submit" class="btn btn-primary w-full h-11">Cek</button>
        </form>

    {{-- ================= Form booking ================= --}}
    @elseif (! $a['aktif'])
        <div class="kartu p-6 text-center text-muted">Booking online belum dibuka. Silakan datang langsung atau hubungi kasir.</div>
    @else
        <form wire:submit="pesan" class="space-y-5">
            <section>
                <div class="label mb-2">1 · Tanggal</div>
                <div class="flex gap-2 overflow-x-auto pb-1">
                    @foreach ($this->pilihanTanggal as $t)
                        <button type="button" wire:click="$set('tanggal', '{{ $t['nilai'] }}')"
                                @class(['shrink-0 rounded-lg border px-3 py-2 text-center min-w-16', 'border-accent' => $tanggal === $t['nilai'], 'border-line' => $tanggal !== $t['nilai']])>
                            <span class="block text-sm font-semibold">{{ $t['label'] }}</span>
                            <span class="block text-xs text-muted num">{{ $t['tgl'] }}</span>
                        </button>
                    @endforeach
                </div>
            </section>

            @if ($this->pilihanTipe->count() > 1)
                <section>
                    <div class="label mb-2">2 · Konsol</div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="$set('tipe', '')" @class(['btn h-10 px-4', 'btn-primary' => $tipe === ''])>Semua</button>
                        @foreach ($this->pilihanTipe as $tk)
                            <button type="button" wire:click="$set('tipe', '{{ $tk->id }}')" @class(['btn h-10 px-4', 'btn-primary' => $tipe === $tk->id])>{{ $tk->nama }}</button>
                        @endforeach
                    </div>
                </section>
            @endif

            <section>
                <div class="label mb-2">{{ $this->pilihanTipe->count() > 1 ? '3' : '2' }} · Durasi</div>
                <div class="grid grid-cols-3 gap-2">
                    @foreach ($a['durasi'] as $m)
                        <button type="button" wire:click="$set('durasi', {{ $m }})"
                                @class(['rounded-lg border px-2 py-2.5 text-center', 'border-accent' => $durasi === $m, 'border-line' => $durasi !== $m])>
                            <span class="block font-semibold">{{ \App\Models\Sesi::formatDurasi($m * 60) }}</span>
                            @if ($this->tarif)
                                <span class="block text-xs text-muted num">±{{ $rp(intdiv($this->tarif * $m + 59, 60)) }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </section>

            <section>
                <div class="label mb-2">Jam main</div>
                @if ($this->slot === [])
                    <p class="text-sm text-muted">Tidak ada jam tersedia di tanggal ini.</p>
                @else
                    <div class="grid grid-cols-4 gap-2" wire:loading.class="opacity-50" wire:target="tanggal,tipe,durasi">
                        @foreach ($this->slot as $s)
                            <button type="button" wire:key="slot-{{ $s['jam'] }}" wire:click="pilihJam('{{ $s['jam'] }}')" @disabled($s['sisa'] === 0)
                                    @class([
                                        'rounded-lg border py-2 text-center num',
                                        'border-accent bg-surface-2' => $jam === $s['jam'],
                                        'border-line' => $jam !== $s['jam'] && $s['sisa'] > 0,
                                        'border-line opacity-35 line-through' => $s['sisa'] === 0,
                                    ])>
                                <span class="block font-semibold">{{ $s['jam'] }}</span>
                                <span class="block text-[11px] text-muted">{{ $s['sisa'] > 0 ? $s['sisa'].' unit' : 'penuh' }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
                @error('jam') <p class="text-sm text-danger mt-2">{{ $message }}</p> @enderror
            </section>

            <section class="kartu p-4 space-y-3">
                <div class="label">Data pemesan</div>
                <input type="text" wire:model="nama" class="input" placeholder="Nama" maxlength="100" autocomplete="name">
                @error('nama') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                <input type="tel" inputmode="tel" wire:model="telepon" class="input num" placeholder="Nomor WhatsApp" maxlength="20" autocomplete="tel">
                @error('telepon') <p class="text-sm text-danger">{{ $message }}</p> @enderror
                <input type="text" wire:model="catatan" class="input" placeholder="Catatan (opsional), misal: main berdua" maxlength="200">
                <input type="text" wire:model="situs" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">
            </section>

            <button type="submit" class="btn btn-primary w-full h-12 text-base" wire:loading.attr="disabled" wire:target="pesan" @disabled($jam === '')>
                <span wire:loading.remove wire:target="pesan">{{ $jam ? 'Booking jam '.$jam : 'Pilih jam dulu' }}</span>
                <span wire:loading wire:target="pesan">Memproses...</span>
            </button>
            <p class="text-xs text-muted text-center">Pembayaran di kasir. Member otomatis dikenali dari nomor WhatsApp.</p>
        </form>
    @endif
</div>
