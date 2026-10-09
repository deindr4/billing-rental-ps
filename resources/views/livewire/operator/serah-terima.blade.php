<div class="max-w-lg mx-auto" data-rahasia>
    @php
        $r = $this->ringkasan;
        $p = $this->potret;
        $selisih = $this->selisih();
        $setor = $kasFisik === null ? null : (int) $kasFisik - $this->ditinggalEfektif();
    @endphp

    <div class="mb-4">
        <div class="label">Ganti kasir · satu laci</div>
        <h1 class="text-xl font-semibold">Serah Terima Shift</h1>
        <p class="text-sm text-muted">
            <span class="num">{{ $this->shift->nomor }}</span> · dipegang {{ $this->shift->user?->name }} sejak {{ $this->shift->dibuka_pada->format('d/m H:i') }}
        </p>
    </div>

    @include('livewire.operator.partials.ringkasan-kas', ['r' => $r])

    {{-- Yang diteruskan ke kasir berikutnya --}}
    <div class="surface mb-4">
        <div class="px-4 py-2.5 border-b border-line font-medium">Diteruskan ke kasir berikutnya</div>
        @if (empty($p['sesi_main']) && empty($p['belum_bayar']))
            <p class="px-4 py-3 text-sm text-muted">Tidak ada sesi berjalan atau tagihan yang belum dibayar.</p>
        @else
            <ul class="divide-y divide-line text-sm">
                @foreach ($p['sesi_main'] as $s)
                    <li class="px-4 py-2 flex justify-between gap-3">
                        <span><span class="font-medium">{{ $s['unit'] }}</span> <span class="text-muted">· main · {{ $s['pelanggan'] ?: 'Tamu' }}{{ $s['selesai'] ? ' · selesai '.$s['selesai'] : ' · open billing' }}</span></span>
                        @if (($s['sisa'] ?? 1) === 0 && $s['tagihan'] > 0)
                            <span class="text-xs text-st-kosong shrink-0">lunas di muka</span>
                        @else
                            <x-rupiah :nilai="$s['tagihan']" class="shrink-0" />
                        @endif
                    </li>
                @endforeach
                @foreach ($p['belum_bayar'] as $t)
                    <li class="px-4 py-2 flex justify-between gap-3">
                        <span><span class="font-medium">{{ $t['unit'] }}</span> <span class="text-danger">· belum bayar</span> <span class="text-muted num">{{ $t['nomor'] }}</span></span>
                        <x-rupiah :nilai="$t['sisa']" class="shrink-0 text-danger" />
                    </li>
                @endforeach
            </ul>
        @endif
        <p class="px-4 py-2 border-t border-line text-xs text-muted">Pembayaran yang diterima setelah serah terima masuk ke kas kasir berikutnya.</p>
    </div>

    <form wire:submit="serahkan" class="surface p-4 space-y-4">
        {{-- 1. Hitung laci --}}
        <div>
            <label for="kasFisik" class="block text-sm mb-1.5">1. Uang di laci (hitung fisik)</label>
            <x-input-uang id="kasFisik" wire:model.live="kasFisik" class="text-lg" />
            @error('kasFisik') <p class="text-sm text-danger mt-1.5">{{ $message }}</p> @enderror
        </div>

        @if ($selisih !== null)
            <div @class(['rounded-md border px-3 py-2 flex justify-between items-baseline', 'border-line' => $selisih === 0, 'border-danger text-danger' => $selisih !== 0])>
                <span class="text-sm">{{ $selisih === 0 ? 'Kas cocok' : ($selisih > 0 ? 'Kas lebih' : 'Kas kurang') }}</span>
                <x-rupiah :nilai="abs($selisih)" class="text-xl font-semibold" />
            </div>
        @endif

        {{-- 2. Modal ditinggal & setoran --}}
        <div>
            <label for="ditinggal" class="block text-sm mb-1.5">2. Ditinggal di laci untuk kasir berikutnya <span class="text-muted">(modal kembalian)</span></label>
            <x-input-uang id="ditinggal" wire:model.live="ditinggal" />
            @if ($setor !== null)
                <p class="text-sm mt-1.5 flex justify-between">
                    <span class="text-muted">Disetor ke owner / brankas</span>
                    <x-rupiah :nilai="$setor" class="font-semibold" />
                </p>
            @endif
        </div>

        {{-- 3. Penerima --}}
        <div>
            <label for="penerima" class="block text-sm mb-1.5">3. Kasir penerima</label>
            @if ($this->calonPenerima->isEmpty())
                <p class="text-sm text-danger">Belum ada karyawan lain dengan izin buka kas di cabang ini (Admin → Pengguna).</p>
            @else
                <select id="penerima" wire:model.live="penerimaId" class="input">
                    <option value="">Pilih kasir…</option>
                    @foreach ($this->calonPenerima as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}{{ $u->pin ? '' : ' (belum punya PIN)' }}</option>
                    @endforeach
                </select>
            @endif
            @error('penerimaId') <p class="text-sm text-danger mt-1.5">{{ $message }}</p> @enderror
        </div>

        @if ($penerimaId)
            <label class="flex items-start gap-3 rounded-md border border-line px-3 py-2.5 cursor-pointer">
                <input type="checkbox" wire:model.live="hitungUlang" class="mt-0.5 shrink-0">
                <span class="text-sm">
                    <span class="block font-medium">Penerima menghitung ulang uang yang ditinggal</span>
                    <span class="block text-xs text-muted">Bila berbeda, selisihnya tercatat atas serah terima ini (log aktivitas & Analisa Pintar).</span>
                </span>
            </label>
            @if ($hitungUlang)
                <div>
                    <label for="dihitung" class="block text-sm mb-1.5">Hitungan penerima</label>
                    <x-input-uang id="dihitung" wire:model.live="dihitungPenerima" />
                </div>
            @endif

            <div>
                <label for="pin" class="block text-sm mb-1.5">PIN penerima <span class="text-muted">(diketik oleh kasir penerima)</span></label>
                <input id="pin" type="password" inputmode="numeric" maxlength="6" autocomplete="off" wire:model="pin" class="input num tracking-[0.4em]">
                @error('pin') <p class="text-sm text-danger mt-1.5">{{ $message }}</p> @enderror
            </div>
        @endif

        @if (($selisih !== null && $selisih !== 0) || ($hitungUlang && $dihitungPenerima !== null && (int) $dihitungPenerima !== $this->ditinggalEfektif()))
            <div>
                <label for="catatan" class="block text-sm mb-1.5">Keterangan selisih</label>
                <textarea id="catatan" wire:model="catatan" rows="2" class="input h-auto py-2" placeholder="Contoh: kembalian salah Rp 2.000"></textarea>
                @error('catatan') <p class="text-sm text-danger mt-1.5">{{ $message }}</p> @enderror
            </div>
        @endif

        <button type="submit" class="btn btn-primary w-full h-11" wire:loading.attr="disabled" wire:target="serahkan"
                @disabled($kasFisik === null || ! $penerimaId)>
            <span wire:loading.remove wire:target="serahkan">Serahkan laci</span>
            <span wire:loading wire:target="serahkan">Memproses…</span>
        </button>
        <p class="text-xs text-muted text-center">Shift ini ditutup & shift penerima langsung dibuka. Akhir hari tanpa kasir berikutnya? Pakai <a href="{{ route('shift.tutup') }}" wire:navigate class="underline">Tutup Kas</a>.</p>
    </form>
</div>
