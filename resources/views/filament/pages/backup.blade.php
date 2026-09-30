<x-filament-panels::page>
    @php
        $terakhir = $this->terakhir;
        $redup = 'opacity:.65;';
        $baris = 'border-top:1px solid rgba(127,127,127,.18);';
    @endphp

    <x-filament::section>
        <x-slot name="heading">Status</x-slot>
        <div style="display:grid; gap:6px; font-size:14px;">
            @if (! $terakhir)
                <p style="color:#ef4444">Belum pernah ada backup.</p>
            @elseif ($terakhir->lt(now()->subHours(36)))
                <p style="color:#f59e0b">
                    Backup terakhir {{ $terakhir->diffForHumans() }} ({{ $terakhir->format('d/m/Y H:i') }}).
                    Backup otomatis harian tidak berjalan — pastikan scheduler aktif.
                </p>
            @else
                <p>Backup terakhir {{ $terakhir->diffForHumans() }} ({{ $terakhir->format('d/m/Y H:i') }}).</p>
            @endif
            <p style="{{ $redup }}">
                Otomatis setiap hari jam 02:00 selama scheduler berjalan. Isi backup: seluruh database + foto/logo/wallpaper.
                Unduh dan simpan salinannya di tempat lain (flashdisk / Google Drive) secara berkala.
            </p>
            <div style="margin-top:6px;">
                <x-filament::button wire:click="buatSekarang" wire:loading.attr="disabled" icon="heroicon-o-plus">Backup sekarang</x-filament::button>
            </div>
        </div>
    </x-filament::section>

    {{-- ================= Pulihkan (konfirmasi) ================= --}}
    @if ($pulihkanNama)
        <x-filament::section>
            <x-slot name="heading"><span style="color:#ef4444">Pulihkan {{ $pulihkanNama }}</span></x-slot>
            <div style="display:grid; gap:12px; font-size:14px; max-width:560px;">
                <div style="border:1px solid #ef4444; border-radius:8px; padding:12px;">
                    <b>Seluruh data saat ini akan diganti</b> dengan isi backup ini (transaksi, member, pengaturan, pengguna).
                    Sebelum memulihkan, kondisi sekarang otomatis dibackup dulu, jadi masih bisa dikembalikan.
                    Setelah selesai Anda akan diminta login ulang. Jika sinkron cloud aktif, data cloud yang lebih baru akan diambil ulang.
                </div>
                <label style="display:flex; align-items:center; gap:8px;">
                    <x-filament::input.checkbox wire:model="pulihkanUpload" /> Pulihkan juga foto, logo & wallpaper
                </label>
                <label>
                    <span style="display:block; margin-bottom:4px;">Ketik <b>PULIHKAN</b></span>
                    <x-filament::input.wrapper><x-filament::input wire:model="konfirmasi" autocomplete="off" /></x-filament::input.wrapper>
                    @error('konfirmasi') <span style="color:#ef4444; font-size:12px">{{ $message }}</span> @enderror
                </label>
                <label>
                    <span style="display:block; margin-bottom:4px;">Password Anda</span>
                    <x-filament::input.wrapper><x-filament::input type="password" wire:model="password" autocomplete="current-password" /></x-filament::input.wrapper>
                    @error('password') <span style="color:#ef4444; font-size:12px">{{ $message }}</span> @enderror
                </label>
                <div style="display:flex; gap:8px;">
                    <x-filament::button color="danger" wire:click="pulihkan" wire:loading.attr="disabled" icon="heroicon-o-arrow-uturn-left">
                        <span wire:loading.remove wire:target="pulihkan">Pulihkan sekarang</span>
                        <span wire:loading wire:target="pulihkan">Memulihkan... jangan tutup halaman</span>
                    </x-filament::button>
                    <x-filament::button color="gray" wire:click="batalPulihkan">Batal</x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">File backup</x-slot>
        @if ($this->daftar->isEmpty())
            <p style="font-size:14px; {{ $redup }}">Belum ada file.</p>
        @else
            <div style="font-size:14px;">
                @foreach ($this->daftar as $b)
                    <div wire:key="{{ $b['nama'] }}" style="display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:8px; padding:8px 0; {{ $loop->first ? '' : $baris }}">
                        <span>
                            <span style="font-family:monospace">{{ $b['nama'] }}</span><br>
                            <span style="font-size:12px; {{ $redup }}">{{ $b['waktu']->format('d/m/Y H:i') }} · {{ \Illuminate\Support\Number::fileSize($b['ukuran']) }}</span>
                        </span>
                        <span style="display:flex; gap:8px;">
                            <x-filament::button size="xs" color="gray" wire:click="unduh('{{ $b['nama'] }}')" icon="heroicon-o-arrow-down-tray">Unduh</x-filament::button>
                            <x-filament::button size="xs" color="warning" wire:click="mulaiPulihkan('{{ $b['nama'] }}')" icon="heroicon-o-arrow-uturn-left">Pulihkan</x-filament::button>
                            <x-filament::button size="xs" color="danger" wire:click="hapus('{{ $b['nama'] }}')" wire:confirm="Hapus {{ $b['nama'] }}?">Hapus</x-filament::button>
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Unggah file backup</x-slot>
        <x-slot name="description">Pakai file .zip hasil backup aplikasi ini (misalnya dari flashdisk atau server lain), lalu pilih "Pulihkan".</x-slot>
        <form wire:submit="unggah" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; font-size:14px;">
            <input type="file" wire:model="fileBackup" accept=".zip">
            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="fileBackup,unggah">Unggah</x-filament::button>
            <span wire:loading wire:target="fileBackup" style="{{ $redup }}">Mengunggah...</span>
            @error('fileBackup') <span style="color:#ef4444; font-size:12px; width:100%">{{ $message }}</span> @enderror
        </form>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Pengaturan</x-slot>
        <form wire:submit="simpanPengaturan" style="display:grid; gap:12px; font-size:14px; max-width:420px;">
            <label>
                <span style="display:block; margin-bottom:4px;">Simpan backup selama (hari)</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="number" min="1" max="365" wire:model="retensiHari" />
                </x-filament::input.wrapper>
                <span style="font-size:12px; {{ $redup }}">3 backup terbaru selalu disimpan.</span>
            </label>
            <label style="display:flex; align-items:center; gap:8px;">
                <x-filament::input.checkbox wire:model="sertakanUpload" />
                Sertakan file unggahan (logo, wallpaper TV, foto nota & aset)
            </label>
            <div><x-filament::button type="submit">Simpan</x-filament::button></div>
        </form>
    </x-filament::section>
</x-filament-panels::page>
