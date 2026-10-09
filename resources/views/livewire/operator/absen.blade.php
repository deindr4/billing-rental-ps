<div class="max-w-3xl mx-auto">
    <div class="mb-4">
        <div class="label">Kehadiran karyawan</div>
        <h1 class="text-xl font-semibold tracking-tight">Absen</h1>
        <p class="text-sm text-muted">Pilih nama, ketik PIN, lalu ambil foto selfie.</p>
    </div>

    @if ($this->daftar->isEmpty())
        <div class="kartu p-10 text-center text-muted">Belum ada data karyawan untuk cabang ini. Tambahkan di Admin → Karyawan.</div>
    @else
        <div class="grid gap-2.5 grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 mb-5">
            @foreach ($this->daftar as $d)
                @php $k = $d['k']; $buka = $d['buka']; @endphp
                <button type="button" wire:key="k-{{ $k->id }}" wire:click="pilih('{{ $k->id }}')"
                        @class(['kartu p-3 text-left flex items-center gap-3', 'border-accent' => $karyawanId === $k->id])>
                    @if ($k->foto)
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($k->foto) }}" alt="" class="h-11 w-11 rounded-full object-cover shrink-0">
                    @else
                        <span class="h-11 w-11 rounded-full grid place-items-center bg-surface-2 border border-line font-semibold shrink-0">{{ mb_substr($k->nama, 0, 1) }}</span>
                    @endif
                    <span class="min-w-0">
                        <span class="block font-medium truncate">{{ $k->nama }}</span>
                        @if ($buka)
                            <span class="block text-xs text-st-kosong">Masuk {{ $buka->masuk_pada->format('H:i') }}</span>
                        @else
                            <span class="block text-xs text-muted">{{ $k->jabatan ?: 'Belum absen' }}</span>
                        @endif
                    </span>
                </button>
            @endforeach
        </div>
    @endif

    @if ($d = $this->dipilih)
        @php $k = $d['k']; $buka = $d['buka']; @endphp
        <form wire:submit="{{ $buka ? 'pulang' : 'masuk' }}" class="surface p-4 space-y-4 max-w-md">
            <div>
                <div class="font-semibold">{{ $k->nama }}</div>
                <div class="text-sm text-muted">
                    Jadwal hari ini: {{ $jadwal ? $jadwal->nama.' '.$jadwal->label() : 'tidak ada (libur / di luar jadwal)' }}
                    @if ($buka) · masuk {{ $buka->masuk_pada->format('H:i') }}@endif
                </div>
            </div>

            <div>
                <label for="pin-absen" class="block text-sm mb-1.5">PIN</label>
                <input id="pin-absen" type="password" inputmode="numeric" maxlength="6" autocomplete="off" wire:model="pin" class="input num tracking-[0.4em]">
                @error('pin') <p class="text-sm text-danger mt-1.5">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="foto-absen" class="block text-sm mb-1.5">Foto selfie</label>
                {{-- capture=user membuka kamera depan tablet; berfungsi juga di alamat http:// LAN --}}
                <input id="foto-absen" type="file" accept="image/*" capture="user" wire:model="foto" class="block w-full text-sm">
                <div wire:loading wire:target="foto" class="label mt-1.5">Mengunggah foto…</div>
                @if ($foto && method_exists($foto, 'isPreviewable') && $foto->isPreviewable())
                    <img src="{{ $foto->temporaryUrl() }}" alt="Pratinjau" class="mt-2 h-40 rounded-md object-cover">
                @endif
                @error('foto') <p class="text-sm text-danger mt-1.5">{{ $message }}</p> @enderror
            </div>

            <button type="submit" @class(['btn w-full h-11', 'btn-primary' => ! $buka, 'btn-tint tint-oranye' => $buka])
                    wire:loading.attr="disabled" wire:target="masuk,pulang,foto">
                {{ $buka ? 'Absen pulang' : 'Absen masuk' }}
            </button>
        </form>
    @endif
</div>
