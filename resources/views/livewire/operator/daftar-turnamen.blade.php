@php
    $warna = ['draft' => 'var(--text-muted)', 'pendaftaran' => 'var(--status-kosong)', 'berjalan' => 'var(--status-main)', 'selesai' => 'var(--accent)', 'batal' => 'var(--status-offline)'];
@endphp
<div>
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
            <div class="label">Gugur · gugur ganda · liga · fase grup · pendaftaran online & kasir · tampil di billboard</div>
            <h1 class="text-xl font-semibold tracking-tight">Turnamen</h1>
        </div>
        <button type="button" wire:click="tambah" class="btn btn-primary h-10 px-4"><x-ikon name="plus" size="16" /> Turnamen baru</button>
    </div>

    <div class="grid gap-4 lg:grid-cols-[320px_1fr]">
        {{-- ================= Daftar ================= --}}
        <section @class(['space-y-2', 'hidden lg:block' => $this->terpilih])>
            @forelse ($this->daftar as $t)
                <button type="button" wire:key="t-{{ $t->id }}" wire:click="pilih('{{ $t->id }}')"
                        @class(['kartu kartu-status w-full text-left p-3', 'bg-surface-2' => $pilihId === $t->id]) style="--warna-status: {{ $warna[$t->status] }}">
                    <div class="flex justify-between gap-2">
                        <span class="font-semibold truncate">{{ $t->nama }}</span>
                        <span class="chip shrink-0" style="color: {{ $warna[$t->status] }}">{{ \App\Models\Turnamen::STATUS[$t->status] }}</span>
                    </div>
                    <div class="text-xs text-muted mt-1">{{ $t->game }} · {{ $t->mulai_pada->translatedFormat('d M Y H:i') }} · {{ $t->jumlah_peserta }}/{{ $t->kuota }}</div>
                </button>
            @empty
                <div class="kartu p-6 text-center text-sm text-muted">Belum ada turnamen.</div>
            @endforelse
        </section>

        {{-- ================= Detail ================= --}}
        <section @class(['space-y-4', 'hidden lg:block' => ! $this->terpilih])>
            @if ($t = $this->terpilih)
                <button type="button" wire:click="$set('pilihId', '')" class="lg:hidden text-sm text-muted">← Daftar turnamen</button>

                <div class="kartu p-4 space-y-3">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="text-lg font-semibold">{{ $t->nama }}</div>
                            <div class="text-sm text-muted">{{ $t->game }} · {{ $t->mulai_pada->translatedFormat('l, d M Y H:i') }}</div>
                        </div>
                        <span class="chip" style="color: {{ $warna[$t->status] }}; border-color: {{ $warna[$t->status] }}">{{ \App\Models\Turnamen::STATUS[$t->status] }}</span>
                    </div>
                    <div class="flex flex-wrap gap-1.5">
                        <span class="chip">{{ $t->namaFormat() }}{{ $t->format === 'grup_gugur' ? " · {$t->jumlah_grup} grup, {$t->lolos_per_grup} lolos" : '' }}{{ in_array($t->format, ['liga', 'grup_gugur'], true) && $t->putaran > 1 ? ' · pulang-pergi' : '' }}</span>
                        @if ($t->labelBonus()) <span class="chip" style="color: var(--accent)">+ {{ $t->labelBonus() }} gratis</span> @endif
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-sm">
                        <div class="rounded-md bg-bg px-3 py-2"><div class="label">Peserta</div><span class="font-semibold num">{{ $this->peserta->count() }}/{{ $t->kuota }}</span></div>
                        <div class="rounded-md bg-bg px-3 py-2"><div class="label">Biaya daftar</div><x-rupiah :nilai="$t->biaya_daftar" class="font-semibold" /></div>
                        <div class="rounded-md bg-bg px-3 py-2"><div class="label">Lunas</div><span class="font-semibold num">{{ $this->peserta->where('status', 'lunas')->count() }}</span></div>
                        <div class="rounded-md bg-bg px-3 py-2"><div class="label">Total hadiah</div><x-rupiah :nilai="$t->total_hadiah" class="font-semibold" /></div>
                    </div>
                    @if ($t->hadiah) <p class="text-sm"><span class="label">Hadiah</span> {{ $t->hadiah }}</p> @endif

                    @if ($juara = $this->juara())
                        <div class="rounded-md px-3 py-2 text-sm space-y-0.5" style="background: color-mix(in srgb, var(--accent) 12%, transparent)">
                            @foreach ($juara as $n => $nama)
                                <div>🏆 Juara {{ $n }}: <b>{{ $nama }}</b></div>
                            @endforeach
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('turnamen.publik', $t->slug) }}" target="_blank" class="btn h-9 px-3 text-sm">Halaman publik ↗</a>
                        @if (in_array($t->status, ['pendaftaran', 'draft'], true))
                            <button type="button" wire:click="ubah" class="btn h-9 px-3 text-sm">Ubah</button>
                            <x-confirm-button action="mulaiTurnamen" title="Tutup pendaftaran & buat bagan?"
                                              text="Peserta yang belum bayar tidak ikut. Urutan diacak (kecuali yang diberi nomor unggulan)."
                                              class="btn-primary h-9 px-3 text-sm">Mulai & buat bagan</x-confirm-button>
                        @endif
                        @if (! in_array($t->status, ['selesai', 'batal'], true))
                            <x-confirm-button action="batalTurnamen" title="Batalkan turnamen?" text="Biaya pendaftaran yang sudah dibayar dikembalikan lewat pembatalan transaksi." danger class="h-9 px-3 text-sm">Batalkan</x-confirm-button>
                        @endif
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-1.5 rounded-md border border-line bg-bg p-1 max-w-sm">
                    @foreach (['peserta' => 'Peserta', 'bagan' => in_array($t->format, ['liga', 'grup_gugur'], true) ? 'Klasemen' : 'Bagan', 'keuangan' => 'Keuangan'] as $k => $n)
                        <button type="button" wire:click="$set('tab', '{{ $k }}')" @class(['h-8 rounded text-sm font-medium', 'bg-surface-2 text-fg border border-line' => $tab === $k, 'text-muted' => $tab !== $k])>{{ $n }}</button>
                    @endforeach
                </div>

                {{-- ---------- Peserta ---------- --}}
                @if ($tab === 'peserta')
                    @if ($t->status === 'pendaftaran')
                        <form wire:submit="tambahPeserta" class="kartu p-3 grid gap-2 sm:grid-cols-[1fr_180px_auto]">
                            <input type="text" wire:model="pesertaNama" class="input" placeholder="Nama / gamer tag" maxlength="100">
                            <input type="tel" wire:model="pesertaTelepon" class="input num" placeholder="No. WhatsApp" maxlength="20">
                            <button type="submit" class="btn btn-primary h-10 px-4">Daftarkan</button>
                            @error('pesertaNama') <p class="text-sm text-danger sm:col-span-3">{{ $message }}</p> @enderror
                            @error('pesertaTelepon') <p class="text-sm text-danger sm:col-span-3">{{ $message }}</p> @enderror
                        </form>
                    @endif

                    <div class="kartu divide-y divide-line">
                        @forelse ($this->peserta as $i => $p)
                            <div wire:key="p-{{ $p->id }}" class="px-4 py-2.5 flex flex-wrap items-center gap-3 text-sm">
                                <span class="w-6 text-muted num">{{ $i + 1 }}</span>
                                <span class="flex-1 min-w-0">
                                    <span class="font-medium">{{ $p->nama }}</span>
                                    @if ($p->grup) <span class="chip ml-1">Grup {{ $p->grup }}</span> @endif
                                    <span class="text-muted num"> · {{ $p->telepon }} · {{ $p->sumber }}</span>
                                </span>
                                @if ($t->status === 'pendaftaran')
                                    <input type="number" min="1" value="{{ $p->unggulan }}" placeholder="Unggulan"
                                           wire:change="unggulan('{{ $p->id }}', $event.target.value)" class="input h-8 w-24 num text-sm" title="Nomor unggulan (opsional)">
                                @endif
                                @if ($p->status === 'lunas')
                                    <span class="chip" style="color: var(--status-kosong)">Lunas</span>
                                @else
                                    <button type="button" wire:click="bukaBayar('{{ $p->id }}')" class="btn btn-primary h-8 px-3 text-xs">Bayar <x-rupiah :nilai="$t->biaya_daftar" /></button>
                                @endif
                                @if ($t->status === 'pendaftaran')
                                    <button type="button" wire:click="hapusPeserta('{{ $p->id }}')" wire:confirm="Hapus {{ $p->nama }}?" class="btn btn-ghost h-8 px-2 text-xs text-danger">✕</button>
                                @endif
                            </div>
                        @empty
                            <div class="p-6 text-center text-sm text-muted">Belum ada peserta.</div>
                        @endforelse
                    </div>

                {{-- ---------- Bagan / klasemen ---------- --}}
                @elseif ($tab === 'bagan')
                    @if ($this->bagian === [])
                        <div class="kartu p-6 text-center text-sm text-muted">Bagan / jadwal dibuat saat turnamen dimulai ({{ $t->namaFormat() }}).</div>
                    @else
                        <div class="space-y-5">
                            @include('turnamen.bagian', ['bagian' => $this->bagian, 'ubah' => $t->status === 'berjalan', 'units' => $this->units])
                        </div>
                    @endif

                {{-- ---------- Keuangan ---------- --}}
                @else
                    @php $k = $this->keuangan; @endphp
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach (['perkiraan' => 'Perkiraan (kuota penuh)', 'realisasi' => 'Realisasi (peserta lunas)'] as $kunci => $judul)
                            @php $d = $k[$kunci]; @endphp
                            <div class="kartu p-4 space-y-1.5 text-sm">
                                <div class="label">{{ $judul }}</div>
                                <div class="flex justify-between"><span class="text-muted">Pemasukan ({{ $d['peserta'] }} × <x-rupiah :nilai="$t->biaya_daftar" />)</span><x-rupiah :nilai="$d['masuk']" /></div>
                                @if ($t->labelBonus())
                                    <div class="flex justify-between"><span class="text-muted">Modal bonus {{ $t->labelBonus() }} ({{ $d['peserta'] * $t->bonus_qty }} × <x-rupiah :nilai="$k['hpp_bonus']" />)</span><span>− <x-rupiah :nilai="$d['modal_bonus']" /></span></div>
                                @endif
                                <div class="flex justify-between border-t border-line pt-1.5 font-semibold"><span>Dana bersih</span><x-rupiah :nilai="$d['bersih']" /></div>
                                <div class="flex justify-between"><span class="text-muted">Total hadiah</span><span>− <x-rupiah :nilai="$d['hadiah']" /></span></div>
                                <div @class(['flex justify-between border-t border-line pt-1.5 font-bold', 'text-danger' => $d['sisa'] < 0, 'text-accent' => $d['sisa'] >= 0])>
                                    <span>{{ $d['sisa'] < 0 ? 'Rugi' : 'Sisa untuk rental' }}</span><x-rupiah :nilai="abs($d['sisa'])" />
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="kartu p-4 text-sm space-y-2">
                        <div class="label">Saran total hadiah (dari dana bersih bila kuota penuh)</div>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach ($k['saran'] as $persen => $nilai)
                                <div class="rounded-md bg-bg px-3 py-2"><div class="text-xs text-muted">{{ $persen }}%</div><x-rupiah :nilai="$nilai" class="font-semibold" /></div>
                            @endforeach
                        </div>
                        @if ($t->labelBonus() && $k['hpp_bonus'] === 0)
                            <p class="text-xs text-muted">Modal {{ $t->bonusProduk?->nama }} belum tercatat (catat stok masuk dengan harga beli di menu Stok) — modal bonus dihitung Rp0.</p>
                        @endif
                        <p class="text-xs text-muted">Ubah total hadiah lewat tombol <b>Ubah</b>. Contoh pembagian: juara 1 = 50%, juara 2 = 30%, juara 3 = 20% dari total hadiah.</p>
                    </div>
                @endif
            @else
                <div class="kartu p-10 text-center text-muted">Pilih turnamen, atau buat turnamen baru.</div>
            @endif
        </section>
    </div>

    {{-- ================= Form turnamen ================= --}}
    <x-sheet wire:model="formBuka" :judul="$editId ? 'Ubah turnamen' : 'Turnamen baru'">
        <form id="form-turnamen" wire:submit="simpan" class="space-y-4">
            <div>
                <label class="block text-sm mb-1.5">Nama turnamen</label>
                <input type="text" wire:model="form.nama" class="input" maxlength="120" placeholder="Turnamen FC 25 Sabtu Malam">
                @error('form.nama') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm mb-1.5">Game</label>
                    <input type="text" wire:model="form.game" class="input" maxlength="100" placeholder="EA FC 25">
                    @error('form.game') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm mb-1.5">Mulai</label>
                    <input type="datetime-local" wire:model="form.mulai_pada" class="input">
                </div>
            </div>
            {{-- Format turnamen --}}
            <div>
                <label class="block text-sm mb-1.5">Format</label>
                <select wire:model.live="form.format" class="input" @disabled($editId && $this->terpilih?->status !== 'pendaftaran')>
                    @foreach (\App\Models\Turnamen::FORMAT as $kode => [$namaF, $ketF])
                        <option value="{{ $kode }}">{{ $namaF }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-muted mt-1">{{ \App\Models\Turnamen::FORMAT[$form['format'] ?? 'gugur'][1] ?? '' }}</p>
            </div>
            @if (($form['format'] ?? '') === 'grup_gugur')
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-sm mb-1.5">Jumlah grup</label>
                        <select wire:model="form.jumlah_grup" class="input">
                            @foreach (range(2, 8) as $g) <option value="{{ $g }}">{{ $g }} grup ({{ implode(', ', array_map(fn ($i) => chr(65 + $i), range(0, $g - 1))) }})</option> @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm mb-1.5">Lolos per grup</label>
                        <select wire:model="form.lolos_per_grup" class="input">
                            <option value="1">Juara grup</option>
                            <option value="2">Juara & runner-up</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm mb-1.5">Fase grup</label>
                        <select wire:model="form.putaran" class="input"><option value="1">Sekali bertemu</option><option value="2">Pulang-pergi</option></select>
                    </div>
                </div>
            @elseif (($form['format'] ?? '') === 'liga')
                <div>
                    <label class="block text-sm mb-1.5">Putaran</label>
                    <select wire:model="form.putaran" class="input"><option value="1">Sekali bertemu</option><option value="2">Pulang-pergi (2x bertemu)</option></select>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm mb-1.5">Biaya daftar</label>
                    <x-input-uang wire:model.live.debounce.500ms="form.biaya_daftar" />
                </div>
                <div>
                    <label class="block text-sm mb-1.5">Kuota peserta</label>
                    <input type="number" wire:model.live.debounce.500ms="form.kuota" class="input num" min="2" max="128">
                    @error('form.kuota') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Bundling F&B gratis untuk setiap pendaftar --}}
            <div class="grid grid-cols-[1fr_90px] gap-3">
                <div>
                    <label class="block text-sm mb-1.5">Bonus F&amp;B gratis (bundling)</label>
                    <select wire:model.live="form.bonus_produk_id" class="input">
                        <option value="">Tanpa bonus</option>
                        @foreach ($this->produkList as $pr) <option value="{{ $pr->id }}">{{ $pr->nama }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm mb-1.5">Jumlah</label>
                    <input type="number" wire:model.live.debounce.500ms="form.bonus_qty" class="input num" min="1" max="20" @disabled(empty($form['bonus_produk_id']))>
                </div>
            </div>

            <div>
                <label class="block text-sm mb-1.5">Total hadiah (uang)</label>
                <x-input-uang wire:model.live.debounce.500ms="form.total_hadiah" />
            </div>

            {{-- Hitungan cepat: berapa uang masuk & sisa setelah hadiah --}}
            @php $r = $this->ringkasForm; @endphp
            <div class="rounded-md border border-line px-3 py-2 text-sm space-y-1">
                <div class="label">Perkiraan bila kuota penuh</div>
                <div class="flex justify-between"><span class="text-muted">{{ $r['peserta'] }} peserta × <x-rupiah :nilai="$r['biaya']" /></span><x-rupiah :nilai="$r['masuk']" /></div>
                @if ($r['modal_bonus'] > 0 || $r['label_bonus'])
                    <div class="flex justify-between"><span class="text-muted">Modal bonus {{ $r['label_bonus'] }}</span><span>− <x-rupiah :nilai="$r['modal_bonus']" /></span></div>
                @endif
                <div class="flex justify-between font-semibold"><span>Dana bersih</span><x-rupiah :nilai="$r['bersih']" /></div>
                <div class="flex justify-between"><span class="text-muted">Total hadiah</span><span>− <x-rupiah :nilai="$r['hadiah']" /></span></div>
                <div @class(['flex justify-between font-bold border-t border-line pt-1', 'text-danger' => $r['sisa'] < 0, 'text-accent' => $r['sisa'] >= 0])>
                    <span>{{ $r['sisa'] < 0 ? 'Rugi' : 'Sisa untuk rental' }}</span><x-rupiah :nilai="abs($r['sisa'])" />
                </div>
                <div class="text-xs text-muted">Saran hadiah: 50% <x-rupiah :nilai="$r['saran'][50]" /> · 60% <x-rupiah :nilai="$r['saran'][60]" /> · 70% <x-rupiah :nilai="$r['saran'][70]" /></div>
            </div>

            <div>
                <label class="block text-sm mb-1.5">Keterangan hadiah</label>
                <textarea wire:model="form.hadiah" rows="2" class="input" maxlength="1000" placeholder="Juara 1 Rp100.000, juara 2 Rp60.000, juara 3 Rp40.000 + main gratis 2 jam"></textarea>
            </div>
            <div>
                <label class="block text-sm mb-1.5">Aturan</label>
                <textarea wire:model="form.aturan" rows="3" class="input" maxlength="3000"></textarea>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.daftar_online"> Buka pendaftaran online</label>
        </form>
        <x-slot:footer>
            <button type="submit" form="form-turnamen" class="btn btn-primary w-full h-11" wire:loading.attr="disabled" wire:target="simpan">Simpan</button>
        </x-slot:footer>
    </x-sheet>

    {{-- ================= Bayar pendaftaran ================= --}}
    @php $bayarP = $bayarId ? $this->peserta->firstWhere('id', $bayarId) : null; @endphp
    <x-sheet wire:model="bayarBuka" judul="Bayar pendaftaran">
        @if ($bayarP && $t = $this->terpilih)
            <div class="space-y-4">
                <div class="text-center">
                    <div class="text-sm text-muted">{{ $bayarP->nama }}</div>
                    <x-rupiah :nilai="$t->biaya_daftar" class="text-3xl font-semibold" />
                </div>
                <div class="grid grid-cols-3 gap-1">
                    @foreach (\App\Livewire\Operator\Pembayaran::METODE as $k => $n)
                        <button type="button" wire:click="$set('bayarMetode', '{{ $k }}')" @class(['btn h-9 text-sm', 'btn-primary' => $bayarMetode === $k])>{{ $n }}</button>
                    @endforeach
                </div>
                @if ($bayarMetode === 'tunai')
                    <div>
                        <label class="block text-xs text-muted mb-1">Uang diterima</label>
                        <x-input-uang wire:model="bayarDiterima" />
                        @error('bayarDiterima') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>
            <x-slot:footer>
                <button type="button" wire:click="bayar" class="btn btn-primary w-full h-11" wire:loading.attr="disabled" wire:target="bayar">Terima pembayaran</button>
            </x-slot:footer>
        @endif
    </x-sheet>
</div>
