<div>
    <div class="flex flex-wrap items-end justify-between gap-3 mb-4">
        <div>
            <div class="label">Saldo, poin & stamp · {{ $this->jumlahMember }} member</div>
            <h1 class="text-xl font-semibold tracking-tight">Member</h1>
        </div>
        @can('member.kelola')
            <button type="button" wire:click="formBaru" class="btn btn-primary h-10 px-4">
                <x-ikon name="plus" size="16" /> Member baru
            </button>
        @endcan
    </div>

    <div class="grid gap-4 lg:grid-cols-[380px_1fr]">
        {{-- ================= Kiri: cari & daftar ================= --}}
        <section @class(['space-y-3', 'hidden lg:block' => $this->terpilih])>
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-muted" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="search" wire:model.live.debounce.300ms="cari" class="input pl-9" placeholder="Cari nomor HP, nama, atau kode">
            </div>

            <div class="flex flex-wrap gap-1.5">
                <button type="button" wire:click="$set('tier', '')" @class(['chip', 'text-fg border-accent' => $tier === ''])>Semua</button>
                @foreach ($this->aturan->tier() as $t)
                    <button type="button" wire:click="$set('tier', '{{ $t['nama'] }}')" @class(['chip', 'text-fg border-accent' => $tier === $t['nama']])>{{ $t['nama'] }}</button>
                @endforeach
            </div>

            <div class="kartu divide-y divide-line">
                @forelse ($this->daftar as $m)
                    <button type="button" wire:key="m-{{ $m->id }}" wire:click="pilih('{{ $m->id }}')"
                            @class(['w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-surface-2', 'bg-surface-2' => $pilihId === $m->id])>
                        <span class="h-9 w-9 shrink-0 rounded-md grid place-items-center bg-bg border border-line text-xs font-semibold">{{ $m->inisial() }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-2">
                                <span class="font-medium truncate">{{ $m->nama }}</span>
                                @unless ($m->is_active) <span class="chip text-danger">Nonaktif</span> @endunless
                            </span>
                            <span class="block text-xs text-muted num">{{ $m->kode }} · {{ $m->telepon }}</span>
                        </span>
                        <span class="text-right shrink-0">
                            <span class="chip">{{ $m->tier }}</span>
                            <x-rupiah :nilai="$m->saldo" class="block text-xs text-accent mt-1" />
                        </span>
                    </button>
                @empty
                    <div class="p-6 text-center text-sm text-muted">
                        {{ $cari !== '' ? 'Member tidak ditemukan.' : 'Belum ada member.' }}
                    </div>
                @endforelse
            </div>
        </section>

        {{-- ================= Kanan: detail ================= --}}
        <section @class(['space-y-4', 'hidden lg:block' => ! $this->terpilih])>
            @if ($m = $this->terpilih)
                @php $info = $this->infoTier(); @endphp

                <button type="button" wire:click="tutupDetail" class="lg:hidden text-sm text-muted">← Daftar member</button>

                <x-kartu-member :member="$m" :diskon="$info['diskon']" :berikutnya="$info['berikutnya']" class="kartu">
                    @can('member.kelola')
                        <button type="button" wire:click="formUbah" class="text-xs text-accent shrink-0">Ubah</button>
                    @endcan
                </x-kartu-member>

                <div class="grid grid-cols-3 gap-2">
                    <div class="kartu px-3 py-2">
                        <div class="label">Total belanja</div>
                        <x-rupiah :nilai="$m->total_belanja" class="font-semibold" />
                    </div>
                    <div class="kartu px-3 py-2">
                        <div class="label">Kunjungan</div>
                        <span class="font-semibold num">{{ $m->jumlah_kunjungan }}x</span>
                    </div>
                    <div class="kartu px-3 py-2">
                        <div class="label">Terakhir</div>
                        <span class="text-sm">{{ $m->terakhir_kunjungan?->diffForHumans() ?? '-' }}</span>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    @can('member.topup')
                        <button type="button" wire:click="bukaTopUp" class="btn btn-primary h-10 px-4" @disabled(! $m->is_active)>Top up saldo</button>
                    @endcan
                    <button type="button" wire:click="bukaKoreksi" class="btn h-10 px-4">Koreksi</button>
                    <a href="https://wa.me/{{ preg_replace('/^0/', '62', $m->telepon) }}" target="_blank" class="btn h-10 px-4">WhatsApp</a>
                </div>

                {{-- Riwayat --}}
                <div class="kartu">
                    <div class="flex items-center justify-between gap-3 px-4 py-2.5 border-b border-line">
                        <span class="font-medium">Riwayat</span>
                        <div class="flex gap-1">
                            @foreach (\App\Livewire\Operator\Member::AKUN as $kode => $nama)
                                <button type="button" wire:click="$set('akun', '{{ $kode }}')" @class(['chip', 'text-fg border-accent' => $akun === $kode])>{{ $nama }}</button>
                            @endforeach
                        </div>
                    </div>
                    <ul class="divide-y divide-line">
                        @forelse ($this->riwayat as $r)
                            <li wire:key="r-{{ $r->id }}" class="px-4 py-2.5 flex items-start justify-between gap-3 text-sm">
                                <span class="min-w-0">
                                    <span class="block">
                                        {{ \App\Models\MemberMutasi::LABEL_JENIS[$r->jenis] ?? $r->jenis }}
                                        <span class="text-muted">· {{ ucfirst($r->akun) }}</span>
                                    </span>
                                    <span class="block text-xs text-muted truncate">
                                        {{ $r->created_at->format('d/m/Y H:i') }}
                                        · {{ $r->transaksi?->nomor ?? $r->keterangan }}
                                        @if ($r->user) · {{ $r->user->name }} @endif
                                    </span>
                                </span>
                                <span class="text-right shrink-0 num">
                                    @if ($r->akun === 'saldo')
                                        <span @class(['block font-medium', 'text-accent' => $r->jumlah > 0, 'text-danger' => $r->jumlah < 0])>
                                            {{ $r->jumlah > 0 ? '+' : '-' }}<x-rupiah :nilai="abs($r->jumlah)" />
                                        </span>
                                        <span class="block text-xs text-muted">sisa <x-rupiah :nilai="$r->saldo_akhir" /></span>
                                    @else
                                        <span @class(['block font-medium', 'text-accent' => $r->jumlah > 0, 'text-danger' => $r->jumlah < 0])>
                                            {{ $r->jumlah > 0 ? '+' : '' }}{{ number_format($r->jumlah, 0, ',', '.') }}
                                        </span>
                                        <span class="block text-xs text-muted">sisa {{ number_format($r->saldo_akhir, 0, ',', '.') }}</span>
                                    @endif
                                </span>
                            </li>
                        @empty
                            <li class="p-6 text-center text-sm text-muted">Belum ada riwayat.</li>
                        @endforelse
                    </ul>
                </div>
            @else
                <div class="kartu p-10 text-center text-muted">Pilih member untuk melihat saldo, poin, dan riwayat.</div>
            @endif
        </section>
    </div>

    {{-- ================= Form member ================= --}}
    <x-sheet wire:model="formBuka" :judul="$editId ? 'Ubah member' : 'Member baru'">
        <form id="form-member" wire:submit="simpanForm" class="space-y-4">
            <div>
                <label class="block text-sm mb-1.5">Nama</label>
                <input type="text" wire:model="form.nama" class="input" maxlength="100">
                @error('form.nama') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm mb-1.5">Nomor HP / WhatsApp</label>
                <input type="tel" inputmode="tel" wire:model="form.telepon" class="input num" maxlength="20">
                @error('form.telepon') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm mb-1.5">Tanggal lahir <span class="text-muted">(opsional)</span></label>
                    <input type="date" wire:model="form.tanggal_lahir" class="input">
                    @error('form.tanggal_lahir') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm mb-1.5">Email <span class="text-muted">(opsional)</span></label>
                    <input type="email" wire:model="form.email" class="input" maxlength="150">
                    @error('form.email') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label class="block text-sm mb-1.5">Catatan <span class="text-muted">(opsional)</span></label>
                <textarea wire:model="form.catatan" rows="2" class="input" maxlength="500"></textarea>
            </div>
            @if ($editId)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="form.is_active"> Member aktif
                </label>
            @endif
        </form>
        <x-slot:footer>
            <button type="submit" form="form-member" class="btn btn-primary w-full h-11" wire:loading.attr="disabled" wire:target="simpanForm">Simpan</button>
        </x-slot:footer>
    </x-sheet>

    {{-- ================= Top up ================= --}}
    <x-sheet wire:model="topupBuka" :judul="$this->terpilih ? 'Top up · '.$this->terpilih->nama : 'Top up'">
        @if ($this->terpilih)
            <form id="form-topup" wire:submit="simpanTopUp" class="space-y-4">
                <div>
                    <div class="label mb-2">Nominal</div>
                    <div class="grid grid-cols-4 gap-2 mb-2">
                        @foreach (\App\Livewire\Operator\Member::NOMINAL_TOPUP as $n)
                            <button type="button" wire:click="setTopUp({{ $n }})"
                                    @class(['btn h-10 text-sm num', 'btn-primary' => (int) $topupJumlah === $n])>{{ $n / 1000 }}rb</button>
                        @endforeach
                    </div>
                    <x-input-uang wire:model.live.debounce.300ms="topupJumlah" class="text-lg" />
                    @error('topupJumlah') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                    @if ($this->bonusTopUp() > 0)
                        <p class="text-sm text-accent mt-1.5">Bonus saldo +<x-rupiah :nilai="$this->bonusTopUp()" /></p>
                    @endif
                </div>

                <div class="grid grid-cols-3 gap-1">
                    @foreach (\App\Livewire\Operator\Pembayaran::METODE as $kode => $nama)
                        <button type="button" wire:click="$set('topupMetode', '{{ $kode }}')"
                                @class(['btn h-9 text-sm', 'btn-primary' => $topupMetode === $kode])>{{ $nama }}</button>
                    @endforeach
                </div>

                @if ($topupMetode === 'tunai')
                    <div>
                        <label class="block text-xs text-muted mb-1">Uang diterima</label>
                        <x-input-uang wire:model.live.debounce.300ms="topupDiterima" />
                        @error('topupDiterima') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label class="block text-xs text-muted mb-1">Referensi <span class="opacity-70">(opsional)</span></label>
                        <input type="text" wire:model="topupReferensi" class="input" maxlength="100">
                    </div>
                @endif

                <p class="text-xs text-muted">Top up bukan omzet: pendapatan dicatat saat saldo dipakai main/F&B. Uang tunai masuk kas laci shift.</p>
            </form>
            <x-slot:footer>
                @php $kembali = $topupMetode === 'tunai' ? max(0, (int) $topupDiterima - (int) $topupJumlah) : 0; @endphp
                <div class="flex items-center justify-between mb-3 text-sm">
                    <span class="text-muted">Saldo setelah top up</span>
                    <x-rupiah :nilai="$this->terpilih->saldo + (int) $topupJumlah + $this->bonusTopUp()" class="text-xl font-semibold text-accent" />
                </div>
                @if ($kembali > 0)
                    <div class="flex items-center justify-between mb-3 text-sm">
                        <span class="text-muted">Kembalian</span>
                        <x-rupiah :nilai="$kembali" class="font-semibold" />
                    </div>
                @endif
                <button type="submit" form="form-topup" class="btn btn-primary w-full h-11" wire:loading.attr="disabled" wire:target="simpanTopUp">Terima top up</button>
            </x-slot:footer>
        @endif
    </x-sheet>

    {{-- ================= Koreksi ================= --}}
    <x-sheet wire:model="koreksiBuka" judul="Koreksi saldo / poin / stamp">
        <div class="space-y-4">
            <div class="grid grid-cols-3 gap-1">
                @foreach (['saldo' => 'Saldo (Rp)', 'poin' => 'Poin', 'stamp' => 'Stamp'] as $kode => $nama)
                    <button type="button" wire:click="$set('koreksiAkun', '{{ $kode }}')"
                            @class(['btn h-9 text-sm', 'btn-primary' => $koreksiAkun === $kode])>{{ $nama }}</button>
                @endforeach
            </div>
            <div>
                <label class="block text-sm mb-1.5">Jumlah <span class="text-muted">(minus untuk mengurangi, contoh -10)</span></label>
                <input type="number" inputmode="numeric" wire:model="koreksiJumlah" class="input num">
                @error('koreksiJumlah') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm mb-1.5">Alasan</label>
                <input type="text" wire:model="koreksiAlasan" class="input" maxlength="200" placeholder="Contoh: kompensasi TV rusak">
                @error('koreksiAlasan') <p class="text-sm text-danger mt-1">{{ $message }}</p> @enderror
            </div>
            <p class="text-xs text-muted">Koreksi saldo tidak mengubah kas laci. Butuh PIN supervisor/owner.</p>
        </div>
        <x-slot:footer>
            <x-confirm-button action="simpanKoreksi" title="Simpan koreksi?" text="Masukkan PIN penyetuju." confirm-text="Simpan" pin class="btn-primary w-full h-11">
                Simpan koreksi
            </x-confirm-button>
        </x-slot:footer>
    </x-sheet>
</div>
