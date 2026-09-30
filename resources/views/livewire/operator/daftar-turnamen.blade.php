@php
    $warna = ['draft' => 'var(--text-muted)', 'pendaftaran' => 'var(--status-kosong)', 'berjalan' => 'var(--status-main)', 'selesai' => 'var(--accent)', 'batal' => 'var(--status-offline)'];
@endphp
<div>
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
            <div class="label">Sistem gugur · pendaftaran online & kasir · tampil di billboard</div>
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
                    <div class="grid grid-cols-3 gap-2 text-sm">
                        <div class="rounded-md bg-bg px-3 py-2"><div class="label">Peserta</div><span class="font-semibold num">{{ $this->peserta->count() }}/{{ $t->kuota }}</span></div>
                        <div class="rounded-md bg-bg px-3 py-2"><div class="label">Biaya daftar</div><x-rupiah :nilai="$t->biaya_daftar" class="font-semibold" /></div>
                        <div class="rounded-md bg-bg px-3 py-2"><div class="label">Lunas</div><span class="font-semibold num">{{ $this->peserta->where('status', 'lunas')->count() }}</span></div>
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

                <div class="grid grid-cols-2 gap-1.5 rounded-md border border-line bg-bg p-1 max-w-xs">
                    @foreach (['peserta' => 'Peserta', 'bagan' => 'Bagan'] as $k => $n)
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

                {{-- ---------- Bagan ---------- --}}
                @else
                    @if ($this->bagan->isEmpty())
                        <div class="kartu p-6 text-center text-sm text-muted">Bagan dibuat saat turnamen dimulai.</div>
                    @else
                        @php $totalBabak = $this->bagan->keys()->max(); @endphp
                        <div class="flex gap-3 overflow-x-auto pb-2">
                            @foreach ($this->bagan as $babak => $laga)
                                <div class="min-w-64 flex-1 flex flex-col gap-2">
                                    <div class="label text-center">{{ \App\Models\Turnamen::namaBabak($babak, $totalBabak) }}</div>
                                    <div class="flex-1 flex flex-col justify-around gap-2">
                                        @foreach ($laga as $m)
                                            <div wire:key="m-{{ $m->id }}" class="kartu p-3 space-y-2 text-sm" @if ($m->status === 'main') style="border-color: var(--accent)" @endif>
                                                @foreach ([['pesertaA', 'skor_a', 'a'], ['pesertaB', 'skor_b', 'b']] as [$rel, $kol, $sisi])
                                                    @php $pp = $m->$rel; $menang = $m->pemenang_id && $pp && $m->pemenang_id === $pp->id; @endphp
                                                    <div class="flex items-center justify-between gap-2 {{ $menang ? 'font-bold text-accent' : ($m->pemenang_id ? 'text-muted' : '') }}">
                                                        <span class="truncate">{{ $pp?->nama ?? ($m->babak === 1 ? 'BYE' : 'TBD') }}</span>
                                                        @if ($m->peserta_a_id && $m->peserta_b_id && $m->status !== 'selesai')
                                                            <input type="number" min="0" wire:model="skor.{{ $m->id }}.{{ $sisi }}" class="input h-8 w-16 num text-sm">
                                                        @else
                                                            <span class="num">{{ $m->$kol }}</span>
                                                        @endif
                                                    </div>
                                                @endforeach
                                                @if ($m->peserta_a_id && $m->peserta_b_id && $m->status !== 'selesai')
                                                    <div class="flex items-center gap-1.5 pt-1">
                                                        <select wire:model="unitMain.{{ $m->id }}" class="input h-8 py-0 text-xs flex-1">
                                                            <option value="">{{ $m->unit?->nama ?? 'Pilih unit' }}</option>
                                                            @foreach ($this->units as $u) <option value="{{ $u->id }}">{{ $u->nama }}</option> @endforeach
                                                        </select>
                                                        @if ($m->status === 'menunggu')
                                                            <button type="button" wire:click="main('{{ $m->id }}')" class="btn h-8 px-2 text-xs">Main</button>
                                                        @endif
                                                        <button type="button" wire:click="simpanSkor('{{ $m->id }}')" class="btn btn-primary h-8 px-2 text-xs">Skor</button>
                                                    </div>
                                                @elseif ($m->unit && $m->status !== 'selesai')
                                                    <div class="label">{{ $m->unit->nama }}</div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
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
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm mb-1.5">Biaya daftar</label>
                    <x-input-uang wire:model="form.biaya_daftar" />
                </div>
                <div>
                    <label class="block text-sm mb-1.5">Kuota peserta</label>
                    <input type="number" wire:model="form.kuota" class="input num" min="2" max="128">
                    @error('form.kuota') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="block text-sm mb-1.5">Hadiah</label>
                <textarea wire:model="form.hadiah" rows="2" class="input" maxlength="1000" placeholder="Juara 1 Rp500.000 + main gratis 5 jam"></textarea>
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
