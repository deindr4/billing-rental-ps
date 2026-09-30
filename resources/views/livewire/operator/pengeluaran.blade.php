<div>
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
            <div class="label">Kas keluar & petty cash</div>
            <h1 class="text-xl font-semibold tracking-tight">Pengeluaran</h1>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-[380px_1fr]">
        {{-- ================= Kiri: ringkasan + form ================= --}}
        <div class="space-y-4">
            {{-- Ringkasan & plafon --}}
            <section class="kartu p-4">
                <div class="label">Total pengeluaran {{ $tanggal === today()->toDateString() ? 'hari ini' : \Illuminate\Support\Carbon::parse($tanggal)->format('d/m/Y') }}</div>
                <x-rupiah :nilai="$this->totalHari" class="text-2xl font-semibold block mt-1 text-danger" />

                @if ($this->plafon['batas'] > 0)
                    <div class="mt-4">
                        <div class="flex justify-between text-xs mb-1.5">
                            <span class="label">Batas pengeluaran kas laci shift</span>
                            <span class="num text-muted">
                                <x-rupiah :nilai="$this->plafon['terpakai']" /> / <x-rupiah :nilai="$this->plafon['batas']" />
                            </span>
                        </div>
                        <div class="h-2 rounded-full bg-bg border border-line overflow-hidden">
                            <div class="h-full rounded-full {{ $this->plafon['persen'] >= 90 ? 'bg-danger' : ($this->plafon['persen'] >= 70 ? 'bg-st-hampir' : 'bg-accent') }}"
                                 style="width: {{ $this->plafon['persen'] }}%"></div>
                        </div>
                        <div class="text-xs text-muted mt-1.5">
                            Sisa batas <x-rupiah :nilai="$this->plafon['sisa']" class="text-fg" />
                            @unless ($this->shift) · shift belum dibuka @endunless
                        </div>
                    </div>
                @else
                    <div class="text-xs text-muted mt-3">Batas pengeluaran kas laci: tanpa batas</div>
                @endif

            </section>

            {{-- Form input --}}
            <section class="kartu p-4">
                <div class="label mb-3">Input pengeluaran baru</div>

                <form wire:submit="simpan" class="space-y-4">
                    <div>
                        <label class="block text-sm mb-1.5">Nominal</label>
                        <x-input-uang wire:model.live.debounce.300ms="jumlah" class="text-xl h-11" />
                        <div class="grid grid-cols-4 gap-1.5 mt-2">
                            @foreach ([10000, 25000, 50000, 100000] as $n)
                                <button type="button" wire:click="tambahNominal({{ $n }})" class="btn h-8 text-xs num">+{{ $n / 1000 }}k</button>
                            @endforeach
                        </div>
                        @error('jumlah') <p class="text-sm text-danger mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm mb-1.5">Kategori</label>
                        <select wire:model="kategori" class="input">
                            @foreach (\App\Models\Pengeluaran::KATEGORI_MANUAL as $kode => $nama)
                                <option value="{{ $kode }}">{{ $nama }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm mb-1.5">Keterangan / uraian nota</label>
                        <textarea wire:model="keterangan" rows="2" maxlength="255" class="input h-auto py-2"
                                  placeholder="Contoh: 1 galon Aqua + 2 plastik es batu"></textarea>
                        @error('keterangan') <p class="text-sm text-danger mt-1.5">{{ $message }}</p> @enderror
                        <p class="text-xs text-muted mt-1.5">Belanja barang untuk dijual (Indomie, minuman) catat lewat <strong>Stok → Stok Masuk</strong> supaya stok & HPP ikut tercatat.</p>
                    </div>

                    <div>
                        <label class="block text-sm mb-1.5">Sumber dana</label>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach (\App\Models\Pengeluaran::SUMBER_DANA as $kode => $nama)
                                <label @class([
                                    'rounded-md border px-3 py-2 cursor-pointer text-sm flex items-center gap-2',
                                    'border-accent' => $sumberDana === $kode,
                                    'border-line' => $sumberDana !== $kode,
                                ])>
                                    <input type="radio" wire:model.live="sumberDana" value="{{ $kode }}">
                                    {{ $nama }}
                                </label>
                            @endforeach
                        </div>
                        <p class="text-xs text-muted mt-1.5">
                            {{ $sumberDana === 'kas_laci' ? 'Memotong uang di laci kas shift Anda.' : 'Tidak memotong laci kas.' }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm mb-1.5">Foto nota <span class="text-muted">(opsional)</span></label>
                        <input type="file" wire:model="foto" accept="image/*" capture="environment" class="text-sm w-full">
                        <div wire:loading wire:target="foto" class="text-xs text-muted mt-1">Mengunggah...</div>
                        @if ($foto)
                            <img src="{{ $foto->temporaryUrl() }}" alt="Foto nota" class="mt-2 h-24 rounded-md border border-line object-cover">
                        @endif
                        @error('foto') <p class="text-sm text-danger mt-1.5">{{ $message }}</p> @enderror
                    </div>

                    @if ($butuhPin)
                        <div class="rounded-md border border-st-hampir p-3">
                            <label class="block text-sm mb-1.5">PIN supervisor / owner</label>
                            <input type="password" inputmode="numeric" autocomplete="off" maxlength="6"
                                   wire:model="pin" class="input num tracking-[0.4em] text-center" placeholder="••••••">
                        </div>
                    @endif
                    @error('pin') <p class="text-sm text-st-hampir">{{ $message }}</p> @enderror

                    <button type="submit" class="btn btn-primary w-full h-11" wire:loading.attr="disabled" wire:target="simpan,foto">
                        {{ $butuhPin ? 'Setujui & Simpan' : 'Simpan Pengeluaran' }}
                    </button>
                </form>
            </section>
        </div>

        {{-- ================= Kanan: riwayat ================= --}}
        <section class="min-w-0">
            <div class="flex flex-wrap items-center gap-2 mb-3">
                <input type="date" wire:model.live="tanggal" class="input num w-auto">
                <select wire:model.live="filterKategori" class="input w-auto">
                    <option value="">Semua kategori</option>
                    @foreach (\App\Models\Pengeluaran::KATEGORI as $kode => $nama)
                        <option value="{{ $kode }}">{{ $nama }}</option>
                    @endforeach
                </select>
                <span class="label ml-auto">{{ $this->daftar->count() }} catatan</span>
            </div>

            <div class="space-y-2">
                @forelse ($this->daftar as $p)
                    <div wire:key="pgl-{{ $p->id }}" @class(['kartu p-3', 'opacity-60' => $p->isDibatalkan()])>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="chip">{{ \App\Models\Pengeluaran::KATEGORI[$p->kategori] ?? $p->kategori }}</span>
                                    <span class="label">{{ $p->created_at->format('H:i') }} · {{ $p->nomor }}</span>
                                    @if ($p->isDibatalkan())
                                        <span class="badge text-danger">Dibatalkan</span>
                                    @endif
                                </div>
                                <div @class(['mt-1.5', 'line-through' => $p->isDibatalkan()])>{{ $p->keterangan }}</div>
                                <div class="text-xs text-muted mt-1">
                                    {{ \App\Models\Pengeluaran::SUMBER_DANA[$p->sumber_dana] ?? $p->sumber_dana }} · {{ $p->user?->name }}
                                    @if ($p->penyetuju)
                                        · disetujui {{ $p->penyetuju->name }}
                                    @endif
                                </div>
                                @if ($p->isDibatalkan() && $p->alasan_batal)
                                    <div class="text-xs text-danger mt-1">Alasan batal: {{ $p->alasan_batal }}</div>
                                @endif
                            </div>
                            <div class="text-right shrink-0">
                                <x-rupiah :nilai="-$p->jumlah" class="font-semibold text-danger" />
                                <div class="flex justify-end gap-1 mt-2">
                                    @if ($p->fotoUrl())
                                        <a href="{{ $p->fotoUrl() }}" target="_blank" class="btn h-7 px-2 text-xs">Foto</a>
                                    @endif
                                    @unless ($p->isDibatalkan())
                                        <x-confirm-button action="batalkan"
                                                          :params="[$p->id]"
                                                          title="Batalkan pengeluaran?"
                                                          text="Butuh PIN supervisor/owner. Jika dari kas laci, uang dikembalikan ke kas shift Anda."
                                                          confirm-text="Ya, batalkan"
                                                          danger reason pin
                                                          class="h-7 px-2 text-xs">
                                            Batal
                                        </x-confirm-button>
                                    @endunless
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="kartu p-8 text-center text-muted">Belum ada pengeluaran pada tanggal ini.</div>
                @endforelse
            </div>
        </section>
    </div>
</div>
